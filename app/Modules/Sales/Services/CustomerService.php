<?php

namespace App\Modules\Sales\Services;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerService
{
    public function __construct(private AuditTrailService $audit, private Outbox $outbox) {}

    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $data['uuid'] ??= (string) Str::uuid();
            $customer = Customer::query()->create($data);
            $this->audit->record('sales.customer.created', ['module' => 'sales', 'entity_type' => 'customer', 'entity_id' => $customer->uuid, 'new_values' => ['customer_number' => $customer->customer_number, 'name' => $customer->name]]);
            $this->outbox->append('sales.customer.created', ['customer_uuid' => $customer->uuid]);

            return $customer;
        });
    }
}
