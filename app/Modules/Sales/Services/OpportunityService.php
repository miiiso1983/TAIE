<?php

namespace App\Modules\Sales\Services;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Modules\Sales\Models\Contact;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\Pipeline;
use App\Modules\Sales\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OpportunityService
{
    public function __construct(private TenantContext $context, private AuditTrailService $audit, private Outbox $outbox) {}

    public function create(array $data): Opportunity
    {
        return DB::transaction(function () use ($data) {
            $tenantId = $this->context->getTenantId();
            abort_unless(Customer::query()->whereKey($data['customer_id'])->exists(), 422, 'Customer not found.');
            if (! empty($data['contact_id'])) {
                abort_unless(Contact::query()->whereKey($data['contact_id'])->where('customer_id', $data['customer_id'])->exists(), 422, 'Contact does not belong to the selected customer.');
            }
            if (! empty($data['lead_id'])) {
                abort_unless(Lead::query()->whereKey($data['lead_id'])->exists(), 422, 'Lead not found.');
            }
            $pipeline = Pipeline::query()->whereKey($data['pipeline_id'])->first();
            $stage = PipelineStage::query()->whereKey($data['stage_id'])->where('pipeline_id', $data['pipeline_id'])->first();
            abort_unless($pipeline && $stage && (int) $pipeline->tenant_id === $tenantId, 422, 'Invalid pipeline stage.');
            $data['uuid'] ??= (string) Str::uuid();
            $data['probability'] ??= $stage->default_probability;
            $data['status'] = $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open');
            if ($stage->is_won) {
                $data['won_at'] = now();
            }
            if ($stage->is_lost) {
                $data['lost_at'] = now();
            }
            $opportunity = Opportunity::query()->create($data);
            $this->audit->record('sales.opportunity.created', ['module' => 'sales', 'entity_type' => 'opportunity', 'entity_id' => $opportunity->uuid, 'new_values' => ['amount' => $opportunity->amount, 'status' => $opportunity->status]]);
            $this->outbox->append('sales.opportunity.created', ['opportunity_uuid' => $opportunity->uuid]);

            return $opportunity->load(['customer', 'contact', 'pipeline', 'stage']);
        });
    }

    public function moveToStage(Opportunity $opportunity, int $stageId): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $stageId) {
            $locked = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new ConflictHttpException('Closed opportunities cannot change stage.');
            }
            $stage = PipelineStage::query()->whereKey($stageId)->where('pipeline_id', $locked->pipeline_id)->firstOrFail();
            $locked->stage_id = $stage->id;
            $locked->probability = $stage->default_probability;
            if ($stage->is_won) {
                $locked->status = 'won';
                $locked->won_at = now();
            } elseif ($stage->is_lost) {
                $locked->status = 'lost';
                $locked->lost_at = now();
            }
            $locked->save();
            $this->audit->record('sales.opportunity.stage_changed', ['module' => 'sales', 'entity_type' => 'opportunity', 'entity_id' => $locked->uuid, 'new_values' => ['stage_id' => $stage->id, 'status' => $locked->status]]);
            $this->outbox->append('sales.opportunity.stage_changed', ['opportunity_uuid' => $locked->uuid, 'stage_id' => $stage->id, 'status' => $locked->status]);

            return $locked->load(['customer', 'contact', 'pipeline', 'stage']);
        });
    }
}
