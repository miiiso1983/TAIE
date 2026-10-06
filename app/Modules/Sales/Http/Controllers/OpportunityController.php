<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Resources\OpportunityResource;
use App\Modules\Sales\Models\Contact;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Pipeline;
use App\Modules\Sales\Models\PipelineStage;
use App\Modules\Sales\Repositories\OpportunityRepository;
use App\Modules\Sales\Services\OpportunityService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OpportunityController extends Controller
{
    public function index(Request $request, OpportunityRepository $opportunities)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['open', 'won', 'lost'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return OpportunityResource::collection($opportunities->paginate($filters, (int) ($filters['per_page'] ?? 25)));
    }

    public function store(Request $request, OpportunityService $service)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'customer_uuid' => ['required', 'uuid'], 'contact_uuid' => ['nullable', 'uuid'], 'lead_uuid' => ['nullable', 'uuid'], 'pipeline_uuid' => ['required', 'uuid'], 'stage_uuid' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'min:0'], 'currency_code' => ['required', 'string', 'size:3'], 'expected_close_on' => ['nullable', 'date', 'after_or_equal:today'], 'description' => ['nullable', 'string', 'max:8000']]);
        $customer = Customer::query()->where('uuid', $data['customer_uuid'])->firstOrFail();
        $pipeline = Pipeline::query()->where('uuid', $data['pipeline_uuid'])->firstOrFail();
        $stage = PipelineStage::query()->where('uuid', $data['stage_uuid'])->where('pipeline_id', $pipeline->id)->firstOrFail();
        $data['customer_id'] = $customer->id;
        $data['contact_id'] = isset($data['contact_uuid']) ? Contact::query()->where('uuid', $data['contact_uuid'])->where('customer_id', $customer->id)->value('id') : null;
        abort_if(isset($data['contact_uuid']) && ! $data['contact_id'], 422, 'Contact does not belong to the selected customer.');
        $data['lead_id'] = isset($data['lead_uuid']) ? Lead::query()->where('uuid', $data['lead_uuid'])->value('id') : null;
        abort_if(isset($data['lead_uuid']) && ! $data['lead_id'], 422, 'Lead not found.');
        $data['pipeline_id'] = $pipeline->id;
        $data['stage_id'] = $stage->id;
        $data['owner_user_id'] = $request->user()->getKey();
        $data['uuid'] = (string) Str::uuid();
        unset($data['customer_uuid'], $data['contact_uuid'], $data['lead_uuid'], $data['pipeline_uuid'], $data['stage_uuid']);

        return (new OpportunityResource($service->create($data)))->response()->setStatusCode(201);
    }

    public function show(string $uuid, OpportunityRepository $opportunities)
    {
        return new OpportunityResource($opportunities->findByUuid($uuid) ?? abort(404));
    }

    public function move(Request $request, string $uuid, OpportunityService $service, OpportunityRepository $opportunities)
    {
        $data = $request->validate(['stage_uuid' => ['required', 'uuid']]);
        $opportunity = $opportunities->findByUuid($uuid) ?? abort(404);
        $stage = PipelineStage::query()->where('uuid', $data['stage_uuid'])->where('pipeline_id', $opportunity->pipeline_id)->firstOrFail();

        return new OpportunityResource($service->moveToStage($opportunity, $stage->id));
    }
}
