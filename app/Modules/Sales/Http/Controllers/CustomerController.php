<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Resources\CustomerResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::query()->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))->orderBy('name')->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return CustomerResource::collection($customers);
    }

    public function store(Request $request, CustomerService $service)
    {
        $data = $request->validate(['customer_number' => ['required', 'string', 'max:60', Rule::unique('sales_customers', 'customer_number')->where('tenant_id', app(TenantContext::class)->getTenantId())], 'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'], 'type' => ['sometimes', Rule::in(['organization', 'individual'])], 'industry' => ['nullable', 'string', 'max:120'], 'website' => ['nullable', 'url', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'country_code' => ['nullable', 'string', 'size:2'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $data['uuid'] = (string) Str::uuid();

        return (new CustomerResource($service->create($data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $uuid, AuditTrailService $audit, Outbox $outbox)
    {
        $customer = Customer::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['customer_number' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('sales_customers', 'customer_number')->where('tenant_id', app(TenantContext::class)->getTenantId())->ignore($customer->id)], 'name' => ['sometimes', 'required', 'string', 'max:255'], 'type' => ['sometimes', Rule::in(['organization', 'individual'])], 'industry' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'country_code' => ['nullable', 'string', 'size:2'], 'website' => ['nullable', 'url', 'max:255']]);
        DB::transaction(function () use ($customer, $data, $audit, $outbox) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
            $audit->record('sales.customer.updated', ['module' => 'sales', 'entity_type' => 'customer', 'entity_id' => $locked->uuid]);
            $outbox->append('sales.customer.updated', ['customer_uuid' => $locked->uuid]);
        });

        return new CustomerResource($customer->fresh());
    }
}
