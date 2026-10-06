<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Core\Authorization\PolicyEvaluator;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Services\CsvImportService;
use App\Modules\Integrations\Models\IntegrationConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    private function summary(IntegrationConnection $c): array
    {
        return ['uuid' => $c->uuid, 'name' => $c->name, 'provider' => $c->provider, 'source_system' => $c->source_system, 'status' => $c->status, 'transport' => 'canonical_json_v1', 'native_adapter_ready' => false];
    }

    public function catalog(): mixed
    {
        return response()->json(['data' => collect(IntegrationConnection::PROVIDERS)->map(fn ($name, $key) => ['key' => $key, 'name' => $name, 'transport' => 'canonical_json_v1', 'native_adapter_ready' => false])->values()]);
    }

    public function index(): mixed
    {
        return response()->json(['data' => IntegrationConnection::query()->latest('id')->paginate(20)->through(fn ($c) => $this->summary($c))]);
    }

    public function store(Request $r, TenantContext $context, AuditTrailService $audit): mixed
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:120'], 'provider' => ['required', Rule::in(array_keys(IntegrationConnection::PROVIDERS))]]);
        $c = DB::transaction(function () use ($d, $context, $audit) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            abort_if(IntegrationConnection::query()->where('name', $d['name'])->exists(), 409, 'Connection name already exists.');
            $c = IntegrationConnection::query()->create([...$d, 'uuid' => (string) Str::uuid(), 'source_system' => 'int_'.str_replace('-', '', (string) Str::uuid()), 'status' => 'active']);
            $audit->record('integration.created', ['module' => 'integrations', 'entity_type' => 'connection', 'entity_id' => $c->uuid, 'new_values' => ['provider' => $c->provider]]);

            return $c;
        });

        return response()->json(['data' => $this->summary($c)], 201);
    }

    public function status(Request $r, string $uuid, TenantContext $context, AuditTrailService $audit): mixed
    {
        $d = $r->validate(['status' => ['required', Rule::in(['active', 'disabled'])]]);
        $c = DB::transaction(function () use ($d, $uuid, $context, $audit) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $c = IntegrationConnection::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $c->update($d);
            $audit->record('integration.status_changed', ['module' => 'integrations', 'entity_type' => 'connection', 'entity_id' => $c->uuid, 'new_values' => $d]);

            return $c;
        });

        return response()->json(['data' => $this->summary($c)]);
    }

    public function batches(string $uuid): mixed
    {
        $c = IntegrationConnection::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => ImportBatch::query()->where('source_system', $c->source_system)->latest('id')->paginate(20)->through(fn ($b) => ['uuid' => $b->uuid, 'kind' => $b->kind, 'status' => $b->status, 'row_count' => $b->row_count, 'error_count' => $b->error_count, 'committed_at' => $b->committed_at?->toISOString(), 'expires_at' => $b->expires_at->toISOString()])]);
    }

    public function preview(Request $r, string $uuid, TenantContext $context, CsvImportService $service, AuditTrailService $audit): mixed
    {
        abort_if(strlen($r->getContent()) > 1048576, 413, 'Maximum request size is 1 MiB.');
        $d = $r->validate(['schema_version' => ['required', 'in:1'], 'kind' => ['required', Rule::in(array_keys(CsvImportService::HEADERS))], 'records' => ['required', 'array', 'list', 'min:1', 'max:1000'], 'records.*' => ['required', 'array']]);
        $permission = match ($d['kind']) {
            'employees' => 'hr.employee.create', 'customers' => 'sales.customer.create', 'compensation' => 'hr.compensation.write', 'targets' => 'sales.target.write', default => 'data.sales.import'
        };
        abort_unless(app(PolicyEvaluator::class)->allows($r->user(), $permission), 403);
        $rows = [];
        foreach ($d['records'] as $i => $record) {
            abort_if(array_diff(array_keys($record), CsvImportService::HEADERS[$d['kind']]) !== [], 422, 'Unknown fields; use the canonical schema.');
            $values = [];
            foreach (CsvImportService::HEADERS[$d['kind']] as $field) {
                $value = $record[$field] ?? '';
                abort_unless(is_string($value) && strlen($value) <= 2000 && mb_check_encoding($value, 'UTF-8') && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value), 422, 'All values must be UTF-8 strings. Send monetary values as decimal strings.');
                $values[$field] = trim($value);
            }
            $rows[] = ['line' => $i + 2, 'values' => $values];
        }
        $b = DB::transaction(function () use ($uuid, $r, $d, $rows, $context, $service, $audit) {
            Tenant::query()->whereKey($context->getTenantId())->lockForUpdate()->firstOrFail();
            $c = IntegrationConnection::query()->where('uuid', $uuid)->firstOrFail();
            abort_unless($c->status === 'active', 409, 'Connection is disabled.');
            $hash = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $b = ImportBatch::query()->where('source_system', $c->source_system)->where('kind', $d['kind'])->where('content_hash', $hash)->first();
            if ($b?->status === 'imported') {
                return $b;
            }
            $errors = $service->validateRows($rows, $d['kind'], $c->source_system);
            $b ??= new ImportBatch(['uuid' => (string) Str::uuid(), 'kind' => $d['kind'], 'source_system' => $c->source_system, 'content_hash' => $hash, 'created_by' => $r->user()->id]);
            $b->fill(['status' => $errors ? 'invalid' : 'validated', 'row_count' => count($rows), 'error_count' => count($errors), 'payload_encrypted' => $rows, 'errors_encrypted' => $errors, 'expires_at' => now()->addDays(7)])->save();
            $audit->record('integration.previewed', ['module' => 'integrations', 'entity_type' => 'import_batch', 'entity_id' => $b->uuid, 'new_values' => ['connection_uuid' => $c->uuid, 'kind' => $b->kind, 'row_count' => $b->row_count, 'error_count' => $b->error_count]]);

            return $b;
        });

        return response()->json(['data' => ['uuid' => $b->uuid, 'status' => $b->status, 'row_count' => $b->row_count, 'error_count' => $b->error_count, 'errors' => array_slice($b->errors_encrypted ?? [], 0, 100), 'expires_at' => $b->expires_at->toISOString(), 'commit_url' => '/api/advisor/imports/'.$b->uuid.'/commit']]);
    }
}
