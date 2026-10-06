<?php

namespace Tests\Feature;

use App\Core\Contracts\EmbeddingProviderInterface;
use App\Core\Contracts\LLMProviderInterface;
use App\Core\Tenancy\TenantContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\AI\Jobs\IndexKnowledgeDocument;
use App\Modules\AI\Models\KnowledgeChunk;
use App\Modules\AI\Models\KnowledgeDocument;
use App\Modules\AI\Models\KnowledgeSource;
use App\Modules\AI\Services\RagAnswerService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\LeaveWorkflowService;
use App\Modules\Sales\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BusinessModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_api_is_tenant_scoped_and_does_not_expose_sensitive_fields(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('s', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['hr.employee.create', 'hr.employee.read']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/hr/employees', ['employee_number' => 'E-1', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'personal_email' => 'private@example.com', 'phone' => '+100000000', 'date_of_birth' => '1990-01-01'])
            ->assertCreated()->assertJsonPath('data.employee_number', 'E-1')->assertJsonMissingPath('data.phone')->assertJsonMissingPath('data.personal_email');
        app(TenantContext::class)->setTenant($tenant);
        $employee = Employee::query()->firstOrFail();
        $this->assertDatabaseHas('hr_employees', ['id' => $employee->id, 'tenant_id' => $tenant->id]);

        $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherEmployee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'E-2', 'first_name' => 'Other', 'last_name' => 'Person']);
        app(TenantContext::class)->setTenant($tenant);
        $this->getJson('/api/hr/employees/'.$otherEmployee->uuid)->assertNotFound();
    }

    public function test_sales_customer_creation_writes_audit_and_outbox_in_tenant(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('x', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['sales.customer.create', 'sales.customer.read']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/sales/customers', ['customer_number' => 'C-1', 'name' => 'Acme', 'type' => 'organization'])
            ->assertCreated()->assertJsonPath('data.name', 'Acme');
        app(TenantContext::class)->setTenant($tenant);
        $customer = Customer::query()->firstOrFail();
        $this->assertSame($tenant->id, $customer->tenant_id);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'sales.customer.created']);
        $this->assertDatabaseHas('outbox_events', ['tenant_id' => $tenant->id, 'event_type' => 'sales.customer.created']);
    }

    public function test_leave_request_reserves_balance_and_approval_moves_it_to_used_days(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('l', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['hr.leave.create', 'hr.leave.approve']);
        $context = app(TenantContext::class);
        $context->setTenant($tenant);
        $employee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'E-LEAVE', 'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $type = LeaveType::query()->create(['uuid' => (string) Str::uuid(), 'code' => 'ANNUAL', 'name' => 'Annual leave']);
        $balance = LeaveBalance::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => (int) now()->format('Y'), 'allocated_days' => 20]);

        $request = app(LeaveWorkflowService::class)->submit($employee, $type->id, now()->startOfYear()->toDateString(), now()->startOfYear()->addDays(1)->toDateString(), '2');
        $this->assertSame('pending', $request->status);
        $this->assertSame('2.00', $balance->fresh()->pending_days);
        $result = app(LeaveWorkflowService::class)->decide($request, $employee, true, 'Approved');
        $this->assertSame('approved', $result->status);
        $this->assertSame('0.00', $balance->fresh()->pending_days);
        $this->assertSame('2.00', $balance->fresh()->used_days);
        try {
            app(LeaveWorkflowService::class)->submit($employee, $type->id, now()->startOfYear()->toDateString(), now()->startOfYear()->addDays(1)->toDateString(), '1');
            $this->fail('An overlapping leave request must be rejected.');
        } catch (HttpException) {
            $this->assertTrue(true);
        }

        $autoType = LeaveType::query()->create(['uuid' => (string) Str::uuid(), 'code' => 'PERSONAL', 'name' => 'Personal leave', 'requires_approval' => false]);
        $autoBalance = LeaveBalance::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $autoType->id, 'year' => (int) now()->format('Y'), 'allocated_days' => 5]);
        $auto = app(LeaveWorkflowService::class)->submit($employee, $autoType->id, now()->startOfYear()->addDays(5)->toDateString(), now()->startOfYear()->addDays(5)->toDateString(), '1');
        $this->assertSame('approved', $auto->status);
        $this->assertSame('1.00', $autoBalance->fresh()->used_days);
    }

    public function test_leave_admin_can_configure_types_and_balances(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('b', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['hr.leave.manage']);
        app(TenantContext::class)->setTenant($tenant);
        $employee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'E-ADMIN', 'user_id' => $user->id, 'first_name' => 'Grace', 'last_name' => 'Hopper']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $type = $this->postJson('/api/hr/leave-types', ['code' => 'ANNUAL', 'name' => 'Annual leave', 'annual_allowance_days' => 20])->assertCreated()->json('data.uuid');
        $this->putJson('/api/hr/leave-balances', ['employee_uuid' => $employee->uuid, 'leave_type_uuid' => $type, 'year' => (int) now()->format('Y'), 'allocated_days' => 20])
            ->assertOk()->assertJsonPath('data.allocated_days', '20.00');
    }

    public function test_sales_contact_and_lead_api_writes_tenant_owned_records(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('c', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['sales.customer.create', 'sales.customer.read', 'sales.contact.write', 'sales.contact.read', 'sales.lead.write', 'sales.lead.read']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $customerUuid = $this->postJson('/api/sales/customers', ['customer_number' => 'CRM-1', 'name' => 'Northwind'])->assertCreated()->json('data.uuid');
        $contact = $this->postJson('/api/sales/customers/'.$customerUuid.'/contacts', ['first_name' => 'Sam', 'last_name' => 'Taylor', 'email' => 'sam@example.com', 'is_primary' => '1'])->assertCreated();
        $this->assertTrue($contact->json('data.is_primary'));
        $lead = $this->postJson('/api/sales/leads', ['first_name' => 'Alex', 'last_name' => 'Morgan', 'company_name' => 'Contoso', 'email' => 'alex@example.com'])->assertCreated();
        $this->assertSame('new', $lead->json('data.status'));
        $this->getJson('/api/sales/leads')->assertOk()->assertJsonPath('data.0.uuid', $lead->json('data.uuid'));
        $this->assertDatabaseHas('sales_contacts', ['tenant_id' => $tenant->id, 'email' => 'sam@example.com']);
        $this->assertDatabaseHas('sales_leads', ['tenant_id' => $tenant->id, 'email' => 'alex@example.com', 'owner_user_id' => $user->id]);
    }

    public function test_sales_opportunities_are_created_and_closed_through_tenant_pipeline_stages(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('o', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['sales.customer.create', 'sales.pipeline.manage', 'sales.opportunity.write', 'sales.opportunity.read']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $customerUuid = $this->postJson('/api/sales/customers', ['customer_number' => 'CLOSE-1', 'name' => 'Acme'])->assertCreated()->json('data.uuid');
        $pipeline = $this->postJson('/api/sales/pipelines', ['name' => 'Direct sales', 'stages' => [
            ['name' => 'Qualified', 'probability' => 30],
            ['name' => 'Won', 'probability' => 100, 'is_won' => true],
        ]])->assertCreated()->json('data');
        $opportunity = $this->postJson('/api/sales/opportunities', ['name' => 'Annual agreement', 'customer_uuid' => $customerUuid, 'pipeline_uuid' => $pipeline['uuid'], 'stage_uuid' => $pipeline['stages'][0]['uuid'], 'amount' => 12000, 'currency_code' => 'USD'])
            ->assertCreated()->json('data');
        $this->postJson('/api/sales/opportunities/'.$opportunity['uuid'].'/stage', ['stage_uuid' => $pipeline['stages'][1]['uuid']])
            ->assertOk()->assertJsonPath('data.status', 'won');
    }

    public function test_hr_workforce_payroll_attendance_recruiting_and_case_routes(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('h', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['hr.compensation.read', 'hr.compensation.write', 'hr.attendance.read', 'hr.attendance.write', 'hr.performance.read', 'hr.performance.write', 'hr.recruitment.read', 'hr.recruitment.write', 'hr.employee_relations.read', 'hr.employee_relations.write', 'hr.employee_relations.close', 'data.sensitive.read']);
        app(TenantContext::class)->setTenant($tenant);
        $employee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'HR-OPS', 'user_id' => $user->id, 'first_name' => 'Taylor', 'last_name' => 'Jones']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);

        $this->postJson('/api/hr/employees/'.$employee->uuid.'/compensation', ['annual_base' => '125000.00', 'currency_code' => 'usd', 'effective_from' => today()->toDateString()])->assertCreated()->assertJsonPath('data.annual_base', '125000.00');
        $this->assertNotSame('125000.00', DB::table('hr_compensation_records')->value('annual_base_encrypted'));
        $this->postJson('/api/hr/attendance', ['employee_uuid' => $employee->uuid, 'work_date' => today()->toDateString(), 'status' => 'present', 'minutes_worked' => 480])->assertCreated();
        $cycle = $this->postJson('/api/hr/performance/cycles', ['name' => 'FY Review', 'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()])->assertCreated()->json('data.uuid');
        $this->postJson('/api/hr/performance/goals', ['employee_uuid' => $employee->uuid, 'cycle_uuid' => $cycle, 'title' => 'Complete onboarding'])->assertCreated();
        $this->postJson('/api/hr/performance/reviews', ['employee_uuid' => $employee->uuid, 'cycle_uuid' => $cycle, 'score' => 92])->assertCreated();
        $requisition = $this->postJson('/api/hr/requisitions', ['title' => 'Sales representative', 'status' => 'open'])->assertCreated()->json('data.uuid');
        $candidate = $this->postJson('/api/hr/candidates', ['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com'])->assertCreated()->json('data.uuid');
        $this->postJson('/api/hr/applications', ['candidate_uuid' => $candidate, 'requisition_uuid' => $requisition])->assertCreated();
        $case = $this->postJson('/api/hr/employee-relations/cases', ['employee_uuid' => $employee->uuid, 'case_type' => 'grievance', 'subject' => 'Workplace concern', 'description' => 'Confidential detail'])->assertCreated()->json('data.uuid');
        $this->patchJson('/api/hr/employee-relations/cases/'.$case, ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_hr_recruiting_and_performance_lifecycles_enforce_transitions_and_separation_of_duties(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('l', 48)]);
        [$tenant, $reviewer] = $this->tenantUserWithPermissions(['hr.performance.read', 'hr.performance.write', 'hr.performance.approve', 'hr.recruitment.read', 'hr.recruitment.write', 'hr.employee.create', 'hr.onboarding.read', 'hr.onboarding.write', 'data.sensitive.read']);
        $context = app(TenantContext::class);
        $context->setTenant($tenant);
        $reviewerEmployee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'REVIEWER-1', 'user_id' => $reviewer->id, 'first_name' => 'Rae', 'last_name' => 'Reviewer']);
        $approver = User::factory()->create();
        $tenant->users()->attach($approver, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->where('tenant_id', $tenant->id)->firstOrFail();
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $approver->id]);
        $approverEmployee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'APPROVER-1', 'user_id' => $approver->id, 'first_name' => 'Alex', 'last_name' => 'Approver']);
        $this->actingAs($reviewer)->withHeader('X-Tenant', $tenant->uuid);

        $cycleUuid = $this->postJson('/api/hr/performance/cycles', ['name' => 'Annual Cycle', 'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString()])->assertCreated()->json('data.uuid');
        $reviewUuid = $this->postJson('/api/hr/performance/reviews', ['employee_uuid' => $reviewerEmployee->uuid, 'cycle_uuid' => $cycleUuid, 'score' => 78, 'comments' => 'Initial review'])->assertCreated()->json('data.uuid');
        $this->postJson('/api/hr/performance/reviews', ['employee_uuid' => $reviewerEmployee->uuid, 'cycle_uuid' => $cycleUuid, 'score' => 78])->assertStatus(409);
        $this->patchJson('/api/hr/performance/cycles/'.$cycleUuid.'/status', ['status' => 'completed'])->assertStatus(409);
        $this->postJson('/api/hr/performance/reviews/'.$reviewUuid.'/decision', ['status' => 'approved'])->assertForbidden();
        $this->actingAs($approver)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/hr/performance/reviews/'.$reviewUuid.'/decision', ['status' => 'needs_revision', 'decision_notes' => 'Add measurable outcomes'])->assertOk()->assertJsonPath('data.status', 'needs_revision');
        $this->actingAs($reviewer)->withHeader('X-Tenant', $tenant->uuid);
        $this->patchJson('/api/hr/performance/reviews/'.$reviewUuid, ['score' => 84, 'comments' => 'Updated review'])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($approver)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/hr/performance/reviews/'.$reviewUuid.'/decision', ['status' => 'approved', 'decision_notes' => 'Approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson('/api/hr/performance/reviews?cycle_uuid='.$cycleUuid)->assertOk()->assertJsonPath('data.0.decision_notes', 'Approved')->assertJsonPath('data.0.decision_by_uuid', $approver->uuid);
        $this->patchJson('/api/hr/performance/cycles/'.$cycleUuid.'/status', ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->patchJson('/api/hr/performance/cycles/'.$cycleUuid.'/status', ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->patchJson('/api/hr/performance/cycles/'.$cycleUuid.'/status', ['status' => 'completed'])->assertStatus(409);
        $this->postJson('/api/hr/performance/reviews', ['employee_uuid' => $reviewerEmployee->uuid, 'cycle_uuid' => $cycleUuid, 'score' => 80])->assertStatus(409);

        $requisitionUuid = $this->postJson('/api/hr/requisitions', ['title' => 'Product Analyst', 'status' => 'open'])->assertCreated()->json('data.uuid');
        $candidateUuid = $this->postJson('/api/hr/candidates', ['first_name' => 'Morgan', 'last_name' => 'Lee', 'email' => 'morgan-lee@example.com'])->assertCreated()->json('data.uuid');
        $applicationUuid = $this->postJson('/api/hr/applications', ['candidate_uuid' => $candidateUuid, 'requisition_uuid' => $requisitionUuid])->assertCreated()->json('data.uuid');
        $this->postJson('/api/hr/applications/'.$applicationUuid.'/employee', ['employee_number' => 'EMP-MORGAN', 'hired_at' => today()->toDateString()])->assertStatus(409);
        $this->getJson('/api/hr/applications')->assertOk()->assertJsonPath('data.0.uuid', $applicationUuid);
        $this->patchJson('/api/hr/applications/'.$applicationUuid.'/status', ['status' => 'screening'])->assertOk()->assertJsonPath('data.status', 'screening');
        $this->patchJson('/api/hr/applications/'.$applicationUuid.'/status', ['status' => 'hired'])->assertStatus(409);
        $this->patchJson('/api/hr/applications/'.$applicationUuid.'/status', ['status' => 'interview'])->assertOk();
        $this->patchJson('/api/hr/applications/'.$applicationUuid.'/status', ['status' => 'offered'])->assertStatus(409);
        $interviewUuid = $this->postJson('/api/hr/applications/'.$applicationUuid.'/interviews', ['scheduled_at' => now()->addDay()->toISOString(), 'duration_minutes' => 45, 'interviewer_uuid' => $approverEmployee->uuid])->assertCreated()->json('data.uuid');
        $this->patchJson('/api/hr/interviews/'.$interviewUuid, ['status' => 'completed', 'score' => 91])->assertUnprocessable();
        $this->patchJson('/api/hr/interviews/'.$interviewUuid, ['status' => 'completed', 'score' => 91, 'feedback' => 'Strong analytical reasoning'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/hr/applications/'.$applicationUuid.'/interviews')->assertOk()->assertJsonPath('data.0.score', '91.00');
        $this->patchJson('/api/hr/applications/'.$applicationUuid.'/status', ['status' => 'offered'])->assertOk();
        $this->patchJson('/api/hr/applications/'.$applicationUuid.'/status', ['status' => 'hired'])->assertOk()->assertJsonPath('data.status', 'hired');
        $this->patchJson('/api/hr/applications/'.$applicationUuid.'/status', ['status' => 'withdrawn'])->assertStatus(409);
        $onboarded = $this->postJson('/api/hr/applications/'.$applicationUuid.'/employee', ['employee_number' => 'EMP-MORGAN', 'work_email' => 'morgan@company.example', 'hired_at' => today()->toDateString()])->assertCreated()->assertJsonPath('data.first_name', 'Morgan');
        $this->postJson('/api/hr/applications/'.$applicationUuid.'/employee', ['employee_number' => 'EMP-MORGAN', 'work_email' => 'morgan@company.example', 'hired_at' => today()->toDateString()])->assertOk()->assertJsonPath('data.uuid', $onboarded->json('data.uuid'));
        $employeeId = DB::table('hr_employees')->where('uuid', $onboarded->json('data.uuid'))->value('id');
        $this->assertDatabaseHas('hr_applications', ['uuid' => $applicationUuid, 'employee_id' => $employeeId]);
        $taskUuid = $this->postJson('/api/hr/employees/'.$onboarded->json('data.uuid').'/onboarding-tasks', ['title' => 'Complete security training', 'due_on' => today()->addDays(7)->toDateString(), 'assigned_to_user_uuid' => $approver->uuid])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.uuid');
        $this->getJson('/api/hr/employees/'.$onboarded->json('data.uuid').'/onboarding-tasks')->assertOk()->assertJsonPath('data.0.uuid', $taskUuid)->assertJsonPath('data.0.assigned_to_user_uuid', $approver->uuid);
        $this->patchJson('/api/hr/onboarding-tasks/'.$taskUuid, ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->patchJson('/api/hr/onboarding-tasks/'.$taskUuid, ['status' => 'completed'])->assertOk()->assertJsonPath('data.completed_by_user_uuid', $approver->uuid);
        $this->patchJson('/api/hr/onboarding-tasks/'.$taskUuid, ['status' => 'completed'])->assertStatus(409);
    }

    public function test_monthly_payroll_is_encrypted_and_requires_separate_preparation_approval_and_disbursement(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('p', 48)]);
        [$tenant, $preparer] = $this->tenantUserWithPermissions(['hr.compensation.read', 'hr.compensation.write', 'hr.payroll.read', 'hr.payroll.write', 'hr.payroll.approve', 'hr.payroll.disburse', 'data.sensitive.read']);
        app(TenantContext::class)->setTenant($tenant);
        $employee = Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'PAY-1', 'first_name' => 'Jordan', 'last_name' => 'Payee', 'employment_status' => 'active', 'hired_at' => '2026-01-01']);
        Employee::query()->create(['uuid' => (string) Str::uuid(), 'employee_number' => 'PAY-2', 'first_name' => 'No', 'last_name' => 'Salary', 'employment_status' => 'active', 'hired_at' => '2026-01-01']);
        $this->actingAs($preparer)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/hr/employees/'.$employee->uuid.'/compensation', ['annual_base' => '120000.00', 'currency_code' => 'USD', 'effective_from' => '2026-01-01'])->assertCreated();

        $runResponse = $this->withHeader('Idempotency-Key', 'october-payroll-2026')->postJson('/api/hr/payroll/runs', ['name' => 'October Payroll', 'period' => '2026-10-01', 'currency_code' => 'usd'])->assertCreated();
        $runUuid = $runResponse->json('data.uuid');
        $runResponse->assertJsonPath('data.employee_count', 1)->assertJsonPath('data.excluded_employee_count', 1)->assertJsonPath('data.base_total', '10000.00')->assertJsonPath('data.net_total', '10000.00');
        $this->withHeader('Idempotency-Key', 'october-payroll-2026')->postJson('/api/hr/payroll/runs', ['name' => 'October Payroll', 'period' => '2026-10-01', 'currency_code' => 'usd'])->assertJsonPath('data.uuid', $runUuid);
        $this->assertDatabaseCount('hr_payroll_runs', 1);
        $this->assertNotSame('10000.00', DB::table('hr_payroll_items')->value('base_pay_encrypted'));

        $itemUuid = $this->getJson('/api/hr/payroll/runs/'.$runUuid)->assertOk()->assertJsonPath('data.items.0.base_pay', '10000.00')->json('data.items.0.uuid');
        $this->patchJson('/api/hr/payroll/runs/'.$runUuid.'/items/'.$itemUuid, ['allowances' => '250.00', 'deductions' => '500.00'])->assertOk()->assertJsonPath('data.net_pay', '9750.00');
        $this->withHeader('Idempotency-Key', 'excessive-deduction')->patchJson('/api/hr/payroll/runs/'.$runUuid.'/items/'.$itemUuid, ['deductions' => '20000.00'])->assertStatus(422);
        $this->postJson('/api/hr/payroll/runs/'.$runUuid.'/submit')->assertOk()->assertJsonPath('data.status', 'review');
        $this->postJson('/api/hr/payroll/runs/'.$runUuid.'/approve')->assertForbidden();

        $createMemberWithPermissions = function (string $name, array $permissions) use ($tenant): User {
            $user = User::factory()->create();
            $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
            $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => $name, 'guard_name' => 'api']);
            $permissionIds = collect($permissions)->map(fn ($permission) => Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'api'], ['module' => 'hr'])->id);
            $role->permissions()->sync($permissionIds);
            DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $user->id]);

            return $user;
        };
        $approver = $createMemberWithPermissions('Payroll Approver', ['hr.payroll.approve', 'data.sensitive.read']);
        $payer = $createMemberWithPermissions('Payroll Disburser', ['hr.payroll.disburse', 'data.sensitive.read']);
        $this->actingAs($approver)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/hr/payroll/runs/'.$runUuid.'/return', ['reason' => 'Correct the allowance amount'])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.return_reason', 'Correct the allowance amount');
        $this->actingAs($preparer)->withHeader('X-Tenant', $tenant->uuid);
        $this->withHeader('Idempotency-Key', 'corrected-allowance')->patchJson('/api/hr/payroll/runs/'.$runUuid.'/items/'.$itemUuid, ['allowances' => '300.00'])->assertOk()->assertJsonPath('data.net_pay', '9800.00');
        $this->withHeader('Idempotency-Key', 'resubmit-corrected')->postJson('/api/hr/payroll/runs/'.$runUuid.'/submit')->assertOk()->assertJsonPath('data.return_reason', null);
        $this->actingAs($approver)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/hr/payroll/runs/'.$runUuid.'/approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.net_total', '9800.00');
        $this->postJson('/api/hr/payroll/runs/'.$runUuid.'/mark-paid')->assertForbidden();
        $this->actingAs($payer)->withHeader('X-Tenant', $tenant->uuid);
        $this->postJson('/api/hr/payroll/runs/'.$runUuid.'/mark-paid')->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_by_uuid', $payer->uuid);
        $this->actingAs($preparer)->withHeader('X-Tenant', $tenant->uuid);
        $this->withHeader('Idempotency-Key', 'post-payment-edit')->patchJson('/api/hr/payroll/runs/'.$runUuid.'/items/'.$itemUuid, ['allowances' => '300.00'])->assertStatus(409);
    }

    public function test_sales_activities_targets_and_forecasts_are_operational(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('v', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['sales.customer.create', 'sales.activity.read', 'sales.activity.write', 'sales.target.read', 'sales.target.write', 'sales.forecast.read', 'sales.forecast.write', 'sales.pipeline.manage', 'sales.opportunity.write']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $customer = $this->postJson('/api/sales/customers', ['customer_number' => 'OPS-1', 'name' => 'Acme'])->assertCreated()->json('data.uuid');
        $activity = $this->postJson('/api/sales/activities', ['type' => 'call', 'subject' => 'Intro call', 'subject_type' => 'customer', 'subject_uuid' => $customer])->assertCreated()->json('data.uuid');
        $this->patchJson('/api/sales/activities/'.$activity, ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
        $target = $this->postJson('/api/sales/targets', ['name' => 'Quarterly revenue', 'metric' => 'revenue', 'target_value' => 50000, 'currency_code' => 'USD', 'period_starts_on' => today()->toDateString(), 'period_ends_on' => today()->addMonth()->toDateString()])->assertCreated()->json('data.uuid');
        $this->patchJson('/api/sales/targets/'.$target, ['actual_value' => 52000])->assertOk()->assertJsonPath('data.actual_value', '52000.00');
        $pipeline = $this->postJson('/api/sales/pipelines', ['name' => 'Forecast pipeline', 'stages' => [['name' => 'Commit', 'probability' => 80], ['name' => 'Won', 'probability' => 100, 'is_won' => true]]])->assertCreated()->json('data');
        $this->postJson('/api/sales/opportunities', ['name' => 'Contract', 'customer_uuid' => $customer, 'pipeline_uuid' => $pipeline['uuid'], 'stage_uuid' => $pipeline['stages'][0]['uuid'], 'amount' => 10000, 'currency_code' => 'USD', 'expected_close_on' => today()->addDays(10)->toDateString()])->assertCreated();
        $forecast = $this->postJson('/api/sales/forecasts/generate', ['name' => 'Monthly', 'period_starts_on' => today()->toDateString(), 'period_ends_on' => today()->addMonth()->toDateString(), 'currency_code' => 'USD'])->assertCreated();
        $forecast->assertJsonPath('data.pipeline_amount', '10000.00')->assertJsonPath('data.weighted_amount', '8000.00')->assertJsonPath('data.commit_amount', '10000.00');
    }

    public function test_knowledge_document_upload_is_idempotent_by_content(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('d', 48)]);
        Queue::fake();
        [$tenant, $user] = $this->tenantUserWithPermissions(['ai.knowledge.manage', 'ai.knowledge.read']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $sourceUuid = $this->postJson('/api/knowledge/sources', ['name' => 'Handbook'])->assertCreated()->json('data.uuid');
        $document = $this->postJson('/api/knowledge/sources/'.$sourceUuid.'/documents', ['title' => 'Policy', 'text' => 'Unique policy content'])->assertStatus(202)->json('data.uuid');
        $this->postJson('/api/knowledge/sources/'.$sourceUuid.'/documents', ['title' => 'Different title', 'text' => 'Unique policy content'])
            ->assertOk()->assertJsonPath('data.uuid', $document);
        Queue::assertPushed(IndexKnowledgeDocument::class, 1);
    }

    public function test_rag_uses_only_active_tenant_sources_and_returns_citations(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('r', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['ai.knowledge.sensitive.read']);
        $provider = new class implements EmbeddingProviderInterface, LLMProviderInterface
        {
            public function embed(string $text): array
            {
                return [1.0, 0.0];
            }

            public function model(): string
            {
                return 'test-embedding';
            }

            public function complete(array $messages): array
            {
                return ['content' => 'Policy says refer to [1].', 'usage' => ['total_tokens' => 12]];
            }
        };
        app()->instance(EmbeddingProviderInterface::class, $provider);
        app()->instance(LLMProviderInterface::class, $provider);
        $context = app(TenantContext::class);
        $context->setTenant($tenant);
        $source = KnowledgeSource::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Internal policy', 'sensitivity' => 'internal']);
        $doc = KnowledgeDocument::query()->create(['uuid' => (string) Str::uuid(), 'source_id' => $source->id, 'title' => 'Policy', 'body_encrypted' => 'Policy text', 'content_hash' => hash('sha256', 'Policy text'), 'status' => 'indexed']);
        KnowledgeChunk::query()->create(['document_id' => $doc->id, 'chunk_index' => 0, 'content_encrypted' => 'The policy details.', 'embedding' => [1.0, 0.0], 'embedding_model' => 'test-embedding']);

        $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
        $context->setTenant($otherTenant);
        $otherSource = KnowledgeSource::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Other policy', 'sensitivity' => 'internal']);
        $otherDoc = KnowledgeDocument::query()->create(['uuid' => (string) Str::uuid(), 'source_id' => $otherSource->id, 'title' => 'Foreign', 'body_encrypted' => 'Foreign text', 'content_hash' => hash('sha256', 'Foreign text'), 'status' => 'indexed']);
        KnowledgeChunk::query()->create(['document_id' => $otherDoc->id, 'chunk_index' => 0, 'content_encrypted' => 'Foreign private text.', 'embedding' => [1.0, 0.0], 'embedding_model' => 'test-embedding']);
        $context->setTenant($tenant);

        $result = app(RagAnswerService::class)->answer($user, 'What does the policy say?');
        $this->assertSame('Policy says refer to [1].', $result['answer']);
        $this->assertSame($doc->uuid, $result['citations'][0]['document_uuid']);
        $this->assertCount(1, $result['citations']);
    }

    public function test_employee_updates_require_permission_and_remain_tenant_scoped(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('u', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['hr.employee.create', 'hr.employee.read', 'hr.employee.update']);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $uuid = $this->postJson('/api/hr/employees', ['employee_number' => 'UPDATE-1', 'first_name' => 'Ada', 'last_name' => 'Original'])->assertCreated()->json('data.uuid');
        $this->patchJson('/api/hr/employees/'.$uuid, ['last_name' => 'Updated', 'employment_status' => 'terminated'])->assertOk()->assertJsonPath('data.last_name', 'Updated');
        $this->assertDatabaseHas('hr_employees', ['uuid' => $uuid, 'employment_status' => 'terminated']);
        app(TenantContext::class)->setTenant($tenant);
        $this->assertSame(today()->toDateString(), Employee::query()->where('uuid', $uuid)->firstOrFail()->terminated_at->toDateString());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'hr.employee.updated']);
        $this->assertDatabaseHas('outbox_events', ['tenant_id' => $tenant->id, 'event_type' => 'hr.employee.updated']);
        $this->getJson('/api/hr/employees?search=Updated&status=terminated')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/hr/employees?search=Missing')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/hr/employees', ['employee_number' => 'UPDATE-2', 'first_name' => 'Other', 'last_name' => 'Person'])->assertCreated();
        $this->patchJson('/api/hr/employees/'.$uuid, ['employee_number' => 'UPDATE-2'])->assertUnprocessable();
        $this->patchJson('/api/hr/employees/'.$uuid, ['employment_status' => 'active'])->assertOk();
        $this->assertDatabaseHas('hr_employees', ['uuid' => $uuid, 'terminated_at' => null]);

        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-update']);
        app(TenantContext::class)->setTenant($other);
        $foreign = Employee::query()->create(['employee_number' => 'FOREIGN', 'first_name' => 'Private', 'last_name' => 'Person']);
        $this->patchJson('/api/hr/employees/'.$foreign->uuid, ['first_name' => 'Changed'])->assertNotFound();
        $this->assertDatabaseHas('hr_employees', ['uuid' => $foreign->uuid, 'first_name' => 'Private']);
        app(TenantContext::class)->setTenant($tenant);
        $role = Role::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'hr.employee.update')->value('id'));
        $this->patchJson('/api/hr/employees/'.$uuid, ['first_name' => 'Denied'])->assertForbidden();
    }

    public function test_leave_balances_are_readable_only_for_the_current_tenant(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('b', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['hr.leave.read']);
        app(TenantContext::class)->setTenant($tenant);
        $employee = Employee::query()->create(['employee_number' => 'BAL-1', 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $type = LeaveType::query()->create(['code' => 'ANNUAL', 'name' => 'Annual']);
        LeaveBalance::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'allocated_days' => 20, 'used_days' => 3, 'pending_days' => 2]);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $this->getJson('/api/hr/leave-balances?employee_uuid='.$employee->uuid.'&year=2026')->assertOk()->assertJsonPath('data.0.available_days', '15.00')->assertJsonMissingPath('data.0.employee_id');
        $this->getJson('/api/hr/leave-balances?employee_uuid='.$employee->uuid.'&year=2027')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/hr/leave-balances?employee_uuid='.$employee->uuid.'&year=1900')->assertUnprocessable();
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-balance']);
        app(TenantContext::class)->setTenant($other);
        $foreign = Employee::query()->create(['employee_number' => 'BAL-2', 'first_name' => 'Private', 'last_name' => 'Person']);
        $this->getJson('/api/hr/leave-balances?employee_uuid='.$foreign->uuid.'&year=2026')->assertNotFound();
    }

    public function test_employee_department_validation_and_update_idempotency(): void
    {
        config(['teami.audit_hmac_key' => str_repeat('i', 48)]);
        [$tenant, $user] = $this->tenantUserWithPermissions(['hr.employee.create', 'hr.employee.update']);
        app(TenantContext::class)->setTenant($tenant);
        $department = Department::query()->create(['code' => 'SALES', 'name' => 'Sales']);
        $otherDepartment = Department::query()->create(['code' => 'HR', 'name' => 'HR']);
        $position = Position::query()->create(['code' => 'MANAGER', 'title' => 'Manager', 'department_id' => $department->id]);
        $employee = Employee::query()->create(['employee_number' => 'REL-1', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'department_id' => $department->id, 'position_id' => $position->id]);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid);
        $this->patchJson('/api/hr/employees/'.$employee->uuid, ['department_uuid' => $otherDepartment->uuid])->assertUnprocessable();
        $this->patchJson('/api/hr/employees/'.$employee->uuid, ['department_uuid' => $otherDepartment->uuid, 'position_uuid' => null])->assertOk()->assertJsonPath('data.department.uuid', $otherDepartment->uuid)->assertJsonPath('data.position.uuid', null);
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-relation']);
        app(TenantContext::class)->setTenant($other);
        $foreign = Department::query()->create(['code' => 'PRIVATE', 'name' => 'Private']);
        $this->patchJson('/api/hr/employees/'.$employee->uuid, ['department_uuid' => $foreign->uuid])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'employee-update-key');
        $payload = ['last_name' => 'Changed once'];
        $first = $this->patchJson('/api/hr/employees/'.$employee->uuid, $payload)->assertOk()->json();
        $second = $this->patchJson('/api/hr/employees/'.$employee->uuid, $payload)->assertOk()->json();
        $this->assertSame($first, $second);
        $this->assertSame(2, DB::table('outbox_events')->where('tenant_id', $tenant->id)->where('event_type', 'hr.employee.updated')->count());
        $this->patchJson('/api/hr/employees/'.$employee->uuid, ['last_name' => 'Different payload'])->assertConflict();
    }

    private function tenantUserWithPermissions(array $names): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant', 'slug' => 'tenant']);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Business Operator', 'guard_name' => 'api']);
        $permissions = collect($names)->map(fn ($name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['module' => str_starts_with($name, 'hr.') ? 'hr' : 'sales']));
        $role->permissions()->sync($permissions->pluck('id')->all());
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $user->id]);

        return [$tenant, $user];
    }
}
