<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Contact;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CRMController extends Controller
{
    public function contacts(string $customerUuid)
    {
        $customer = Customer::query()->where('uuid', $customerUuid)->firstOrFail();
        $contacts = Contact::query()->where('customer_id', $customer->id)->orderBy('last_name')->orderBy('first_name')->paginate(50);

        return response()->json(['data' => $contacts->getCollection()->map(fn (Contact $contact) => ['uuid' => $contact->uuid, 'first_name' => $contact->first_name, 'last_name' => $contact->last_name, 'job_title' => $contact->job_title, 'email' => $contact->email, 'phone' => $contact->phone, 'is_primary' => $contact->is_primary, 'status' => $contact->status]), 'meta' => ['current_page' => $contacts->currentPage(), 'last_page' => $contacts->lastPage()]]);
    }

    public function createContact(Request $request, string $customerUuid, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'job_title' => ['nullable', 'string', 'max:180'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'is_primary' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $customer = Customer::query()->where('uuid', $customerUuid)->firstOrFail();
        $contact = DB::transaction(function () use ($data, $customer, $audit, $outbox) {
            if (filter_var($data['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                Contact::query()->where('customer_id', $customer->id)->update(['is_primary' => false]);
            }
            $contact = Contact::query()->create(['uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, ...$data]);
            $audit->record('sales.contact.created', ['module' => 'sales', 'entity_type' => 'contact', 'entity_id' => $contact->uuid, 'new_values' => ['customer_uuid' => $customer->uuid, 'first_name' => $contact->first_name, 'last_name' => $contact->last_name, 'email' => $contact->email]]);
            $outbox->append('sales.contact.created', ['contact_uuid' => $contact->uuid, 'customer_uuid' => $customer->uuid]);

            return $contact;
        });

        return response()->json(['data' => ['uuid' => $contact->uuid, 'first_name' => $contact->first_name, 'last_name' => $contact->last_name, 'email' => $contact->email, 'phone' => $contact->phone, 'is_primary' => $contact->is_primary]], 201);
    }

    public function leads(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['new', 'contacted', 'qualified', 'disqualified', 'converted'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $leads = Lead::query()->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))->latest()->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $leads->getCollection()->map(fn (Lead $lead) => ['uuid' => $lead->uuid, 'first_name' => $lead->first_name, 'last_name' => $lead->last_name, 'company_name' => $lead->company_name, 'email' => $lead->email, 'phone' => $lead->phone, 'source' => $lead->source, 'status' => $lead->status, 'score' => $lead->score]), 'meta' => ['current_page' => $leads->currentPage(), 'last_page' => $leads->lastPage()]]);
    }

    public function createLead(Request $request, AuditTrailService $audit, Outbox $outbox)
    {
        $data = $request->validate(['first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'company_name' => ['nullable', 'string', 'max:180'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'source' => ['nullable', 'string', 'max:80'], 'score' => ['nullable', 'integer', 'between:0,100'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $lead = DB::transaction(function () use ($data, $request, $audit, $outbox) {
            $lead = Lead::query()->create(['uuid' => (string) Str::uuid(), 'owner_user_id' => $request->user()->getKey(), 'status' => 'new', ...$data]);
            $audit->record('sales.lead.created', ['module' => 'sales', 'entity_type' => 'lead', 'entity_id' => $lead->uuid, 'new_values' => ['status' => $lead->status, 'source' => $lead->source]]);
            $outbox->append('sales.lead.created', ['lead_uuid' => $lead->uuid]);

            return $lead;
        });

        return response()->json(['data' => ['uuid' => $lead->uuid, 'first_name' => $lead->first_name, 'last_name' => $lead->last_name, 'company_name' => $lead->company_name, 'status' => $lead->status]], 201);
    }
}
