<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Authorization\PolicyEvaluator;
use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Services\BusinessDataImportService;
use App\Modules\Advisor\Services\CsvImportService;
use App\Modules\Advisor\Services\TabularFileService;
use App\Modules\Integrations\Models\IntegrationConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ImportController extends Controller
{
    private function authorizeKind(Request $r, string $kind): void
    {
        $permission = match ($kind) {
            'products', 'areas' => 'data.catalog.import', 'customer_locations' => 'sales.customer.update', 'employees' => 'hr.employee.create','customers' => 'sales.customer.create', 'compensation' => 'hr.compensation.write', 'targets' => 'sales.target.write',default => 'data.sales.import'
        };
        abort_unless(app(PolicyEvaluator::class)->allows($r->user(), $permission), 403, 'You do not have permission to import this dataset.');
    }

    private function summary(ImportBatch $b): array
    {
        return ['uuid' => $b->uuid, 'kind' => $b->kind, 'source_system' => $b->source_system, 'status' => $b->status, 'row_count' => $b->row_count, 'error_count' => $b->error_count, 'errors' => array_slice($b->errors_encrypted ?? [], 0, 100), 'expires_at' => $b->expires_at->toISOString(), 'committed_at' => $b->committed_at?->toISOString()];
    }

    public function schemas(Request $r): mixed
    {
        $result = [];
        $businessReady = BusinessDataImportService::available();
        foreach (CsvImportService::HEADERS as $kind => $columns) {
            if (BusinessDataImportService::supports($kind) && ! $businessReady) {
                continue;
            }
            $permission = match ($kind) {
                'products', 'areas' => 'data.catalog.import', 'customer_locations' => 'sales.customer.update', 'employees' => 'hr.employee.create','customers' => 'sales.customer.create', 'compensation' => 'hr.compensation.write', 'targets' => 'sales.target.write',default => 'data.sales.import'
            };
            if (app(PolicyEvaluator::class)->allows($r->user(), $permission)) {
                $result[] = ['kind' => $kind, 'columns' => $columns, 'required' => CsvImportService::REQUIRED[$kind]];
            }
        }

        return response()->json(['data' => $result]);
    }

    public function index(Request $r): mixed
    {
        // All batch metadata is protected by both import and sensitive-data permissions.
        return response()->json(['data' => ImportBatch::query()->latest('id')->paginate(20)->through(fn ($b) => $this->summary($b))]);
    }

    public function inspect(Request $r, TabularFileService $files): mixed
    {
        $d = $r->validate(['kind' => ['required', Rule::in(array_keys(CsvImportService::HEADERS))], 'file' => ['required', 'file', 'max:1024']]);
        $this->authorizeKind($r, $d['kind']);
        $result = $files->read($r->file('file')->getRealPath(), strtolower($r->file('file')->getClientOriginalExtension()), 0, true);

        return response()->json(['data' => ['sheets' => $result['sheets'], 'targets' => CsvImportService::HEADERS[$d['kind']]]]);
    }

    public function preview(Request $r, CsvImportService $service, AuditTrailService $audit, TenantContext $context, TabularFileService $files): mixed
    {
        $d = $r->validate(['kind' => ['required', Rule::in(array_keys(CsvImportService::HEADERS))], 'source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'file' => ['required', 'file', 'max:1024'], 'sheet' => ['sometimes', 'integer', 'min:0', 'max:9'], 'mapping' => ['sometimes', 'string', 'max:10000']]);
        abort_if(str_starts_with($d['source_system'], 'int_'), 422, 'Reserved integration namespace; use the integration endpoint.');
        $this->authorizeKind($r, $d['kind']);
        $mapping = isset($d['mapping']) ? json_decode($d['mapping'], true) : [];
        abort_unless(is_array($mapping), 422, 'Invalid column mapping.');
        $table = $files->read($r->file('file')->getRealPath(), strtolower($r->file('file')->getClientOriginalExtension()), (int) ($d['sheet'] ?? 0));
        $rows = $files->normalize($table, $d['kind'], $mapping);
        $hash = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $batch = DB::transaction(function () use ($r, $d, $rows, $hash, $service, $audit, $context) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $batch = ImportBatch::query()->where('kind', $d['kind'])->where('source_system', $d['source_system'])->where('content_hash', $hash)->first();
            if ($batch?->status === 'imported') {
                return $batch;
            }
            $errors = $service->validateRows($rows, $d['kind'], $d['source_system']);
            $batch ??= new ImportBatch(['uuid' => (string) Str::uuid(), 'kind' => $d['kind'], 'source_system' => $d['source_system'], 'content_hash' => $hash, 'created_by' => $r->user()->id]);
            $batch->fill(['status' => $errors ? 'invalid' : 'validated', 'row_count' => count($rows), 'error_count' => count($errors), 'payload_encrypted' => $rows, 'errors_encrypted' => $errors, 'expires_at' => now()->addDays(7)])->save();
            $audit->record('advisor.import.previewed', ['module' => 'advisor', 'entity_type' => 'import_batch', 'entity_id' => $batch->uuid, 'new_values' => ['kind' => $batch->kind, 'row_count' => $batch->row_count, 'error_count' => $batch->error_count]]);

            return $batch;
        });

        return response()->json(['data' => $this->summary($batch)], 200);
    }

    public function commit(Request $r, string $uuid, CsvImportService $service, TenantContext $context, AuditTrailService $audit, Outbox $outbox): mixed
    {
        $result = DB::transaction(function () use ($r, $uuid, $service, $context, $audit, $outbox) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $b = ImportBatch::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $this->authorizeKind($r, $b->kind);
            if (str_starts_with($b->source_system, 'int_')) {
                $c = IntegrationConnection::query()->where('source_system', $b->source_system)->firstOrFail();
                abort_unless(app(PolicyEvaluator::class)->allows($r->user(), 'integration.sync') && app(PolicyEvaluator::class)->allows($r->user(), 'integration.read'), 403);
                abort_unless($c->status === 'active', 409, 'Connection is disabled.');
            }
            if ($b->status === 'imported') {
                return $b;
            }
            abort_unless($b->status === 'validated' && $b->expires_at->isFuture() && $b->payload_encrypted, 409, 'Re-upload and validate the file before committing.');
            $rows = $b->payload_encrypted;
            $errors = $service->validateRows($rows, $b->kind, $b->source_system);
            abort_if($errors !== [], 409, 'Data changed since preview. Re-upload the file to see updated validation errors.');
            $service->write($rows, $b->kind, $b->source_system, $b->id);
            $b->update(['status' => 'imported', 'committed_by' => $r->user()->id, 'committed_at' => now(), 'payload_encrypted' => null, 'errors_encrypted' => null]);
            $audit->record('advisor.import.committed', ['module' => 'advisor', 'entity_type' => 'import_batch', 'entity_id' => $b->uuid, 'new_values' => ['kind' => $b->kind, 'row_count' => $b->row_count]]);
            $outbox->append('advisor.import.committed', ['batch_uuid' => $b->uuid, 'kind' => $b->kind, 'row_count' => $b->row_count]);

            return $b;
        });

        return response()->json(['data' => $this->summary($result)]);
    }
}
