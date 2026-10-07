<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Advisor\Models\IdentityLink;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SourceAuthority;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SourceControlController extends Controller
{
    public const KINDS = ['invoices', 'receipts', 'returns', 'products', 'invoice_lines', 'stock_snapshots', 'product_costs', 'stockouts', 'return_lines'];

    private function ready(): void
    {
        abort_unless(Schema::hasTable('advisor_source_authorities') && Schema::hasTable('advisor_identity_links'), 503, 'Source governance requires the pending schema upgrade.');
    }

    private function lock(): void
    {
        Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
    }

    public function index()
    {
        $this->ready();

        return response()->json(['data' => SourceAuthority::query()->latest('id')->paginate(20)]);
    }

    public function links(Request $r)
    {
        $this->ready();
        $kinds = ['customers', 'products', 'invoices'];
        if ($r->user()->hasTenantPermission(app(TenantContext::class)->getTenantId(), 'hr.employee.read')) {
            $kinds[] = 'employees';
        }

        return response()->json(['data' => IdentityLink::query()->whereIn('kind', $kinds)->when(! $r->user()->hasTenantPermission(app(TenantContext::class)->getTenantId(), 'sales.customer.read'), fn ($q) => $q->where('kind', '!=', 'customers'))->latest('id')->paginate(20)]);
    }

    public function sources()
    {
        $this->ready();

        return response()->json(['data' => ImportBatch::query()->where('status', 'imported')->whereIn('kind', self::KINDS)->select('source_system', 'kind')->distinct()->orderBy('source_system')->orderBy('kind')->get()]);
    }

    public function authority(Request $r, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['kind' => ['required', 'in:'.implode(',', self::KINDS)], 'source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'previous_uuid' => ['nullable', 'uuid'], 'review_note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted']]);

        return DB::transaction(function () use ($r, $d, $audit) {
            $this->lock();
            $previous = SourceAuthority::query()->where('kind', $d['kind'])->latest('version')->first();
            abort_unless(($previous?->uuid) === ($d['previous_uuid'] ?? null), 409, 'تغير إصدار مصدر الحقيقة؛ راجع النسخة الحالية.');
            abort_unless(ImportBatch::query()->where('kind', $d['kind'])->where('source_system', $d['source_system'])->where('status', 'imported')->exists(), 422, 'راجع دفعة مستوردة من المصدر لهذا النوع أولاً.');
            $record = SourceAuthority::query()->create(['uuid' => Str::uuid(), 'kind' => $d['kind'], 'source_system' => $d['source_system'], 'version' => ($previous?->version ?? 0) + 1, 'reviewed_by' => $r->user()->id, 'review_note_encrypted' => $d['review_note']]);
            $audit->record('advisor.source.authority_reviewed', ['module' => 'advisor', 'entity_type' => 'source_authority', 'entity_id' => $record->uuid, 'new_values' => ['kind' => $d['kind'], 'version' => $record->version]]);

            return response()->json(['data' => $record], 201);
        });
    }

    public function link(Request $r, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['kind' => ['required', 'in:employees,customers,products,invoices'], 'source_system' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'external_id' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN][\pL\pN._\/\-]*$/u'], 'previous_uuid' => ['nullable', 'uuid'], 'canonical_uuid' => ['required', 'uuid'], 'review_note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted']]);
        if ($d['kind'] === 'employees') {
            abort_unless($r->user()->hasTenantPermission(app(TenantContext::class)->getTenantId(), 'hr.employee.read'), 403);
        }
        if ($d['kind'] === 'customers') {
            abort_unless($r->user()->hasTenantPermission(app(TenantContext::class)->getTenantId(), 'sales.customer.read'), 403);
        }

        return DB::transaction(function () use ($r, $d, $audit) {
            $this->lock();
            $model = match ($d['kind']) {
                'employees' => Employee::class,'customers' => Customer::class,'products' => Product::class,default => SalesInvoice::class
            };
            $model::query()->where('uuid', $d['canonical_uuid'])->firstOrFail();
            abort_unless(ImportBatch::query()->where('source_system', $d['source_system'])->where('kind', $d['kind'])->where('status', 'imported')->exists(), 422, 'المصدر غير موثق بدفعة مستوردة لهذا النوع.');
            $hash = hash_hmac('sha256', $d['external_id'], (string) config('teami.audit_hmac_key'));
            $previous = IdentityLink::query()->where('source_system', $d['source_system'])->where('kind', $d['kind'])->where('external_hash', $hash)->latest('version')->first();
            abort_if($previous && $previous->status === 'active', 409, 'المعرّف مرتبط بالفعل؛ أوقف الربط القديم مع سبب قبل تصحيحه.');
            abort_unless(($previous?->uuid) === ($d['previous_uuid'] ?? null), 409, 'تغير إصدار الربط؛ راجع النسخة السابقة.');
            $record = IdentityLink::query()->create(['uuid' => Str::uuid(), 'kind' => $d['kind'], 'source_system' => $d['source_system'], 'external_hash' => $hash, 'version' => ($previous?->version ?? 0) + 1, 'status' => 'active', 'external_id_encrypted' => $d['external_id'], 'canonical_uuid' => $d['canonical_uuid'], 'reviewed_by' => $r->user()->id, 'review_note_encrypted' => $d['review_note']]);
            $audit->record('advisor.source.identity_linked', ['module' => 'advisor', 'entity_type' => 'identity_link', 'entity_id' => $record->uuid, 'new_values' => ['kind' => $record->kind]]);

            return response()->json(['data' => $record], 201);
        });
    }

    public function revoke(Request $r, string $uuid, AuditTrailService $audit)
    {
        $this->ready();
        $d = $r->validate(['review_note' => ['required', 'string', 'min:12', 'max:1000'], 'acknowledge' => ['required', 'accepted']]);

        return DB::transaction(function () use ($r, $uuid, $d, $audit) {
            $this->lock();
            $link = IdentityLink::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($link->kind === 'employees') {
                abort_unless($r->user()->hasTenantPermission(app(TenantContext::class)->getTenantId(), 'hr.employee.read'), 403);
            }
            if ($link->kind === 'customers') {
                abort_unless($r->user()->hasTenantPermission(app(TenantContext::class)->getTenantId(), 'sales.customer.read'), 403);
            }
            abort_unless($link->status === 'active', 409, 'هذا الربط متوقف بالفعل.');
            $link->update(['status' => 'revoked', 'revoked_by' => $r->user()->id, 'revoked_at' => now(), 'revocation_note_encrypted' => $d['review_note']]);
            $audit->record('advisor.source.identity_revoked', ['module' => 'advisor', 'entity_type' => 'identity_link', 'entity_id' => $link->uuid]);

            return response()->json(['data' => $link]);
        });
    }
}
