<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Pipeline;
use App\Modules\Sales\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PipelineController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Pipeline::query()->where('status', 'active')->with('stages')->orderBy('name')->get()->map(fn ($pipeline) => ['uuid' => $pipeline->uuid, 'name' => $pipeline->name, 'is_default' => $pipeline->is_default, 'stages' => $pipeline->stages->map(fn ($stage) => ['uuid' => $stage->uuid, 'name' => $stage->name, 'sort_order' => $stage->sort_order, 'default_probability' => $stage->default_probability, 'is_won' => $stage->is_won, 'is_lost' => $stage->is_lost])])]);
    }

    public function store(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('sales_pipelines', 'name')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'stages' => ['required', 'array', 'min:2', 'max:30'], 'stages.*.name' => ['required', 'string', 'max:120'], 'stages.*.probability' => ['required', 'numeric', 'between:0,100'], 'stages.*.is_won' => ['sometimes', 'boolean'], 'stages.*.is_lost' => ['sometimes', 'boolean']]);
        abort_if(collect($data['stages'])->where('is_won', true)->count() > 1 || collect($data['stages'])->where('is_lost', true)->count() > 1, 422, 'A pipeline can have at most one won stage and one lost stage.');
        $pipeline = DB::transaction(function () use ($data, $audit, $outbox) {
            $pipeline = Pipeline::query()->create(['uuid' => (string) Str::uuid(), 'name' => $data['name'], 'is_default' => ! Pipeline::query()->exists()]);
            foreach ($data['stages'] as $index => $stage) {
                PipelineStage::query()->create(['uuid' => (string) Str::uuid(), 'pipeline_id' => $pipeline->id, 'name' => $stage['name'], 'sort_order' => $index + 1, 'default_probability' => $stage['probability'], 'is_won' => $stage['is_won'] ?? false, 'is_lost' => $stage['is_lost'] ?? false]);
            }

            $audit->record('sales.pipeline.created', ['module' => 'sales', 'entity_type' => 'pipeline', 'entity_id' => $pipeline->uuid, 'new_values' => ['name' => $pipeline->name, 'stage_count' => count($data['stages'])]]);
            $outbox->append('sales.pipeline.created', ['pipeline_uuid' => $pipeline->uuid]);

            return $pipeline->load('stages');
        });

        return response()->json(['data' => ['uuid' => $pipeline->uuid, 'name' => $pipeline->name, 'stages' => $pipeline->stages->map(fn ($stage) => ['uuid' => $stage->uuid, 'name' => $stage->name, 'sort_order' => $stage->sort_order])]], 201);
    }
}
