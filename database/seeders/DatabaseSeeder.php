<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([
            'platform.tenant.manage' => 'platform',
            'integration.read' => 'integrations',
            'integration.manage' => 'integrations',
            'integration.sync' => 'integrations',
            'data.import.manage' => 'advisor',
            'data.sales.import' => 'advisor',
            'data.catalog.import' => 'advisor',
            'advisor.data.review' => 'advisor',
            'advisor.forecast.run' => 'advisor',
            'advisor.decision.read' => 'advisor',
            'advisor.decision.manage' => 'advisor',
            'advisor.sales.read' => 'advisor',
            'advisor.report.read' => 'advisor',
            'advisor.report.generate' => 'advisor',
            'advisor.report.manage' => 'advisor',
            'advisor.report.explain' => 'advisor',

            'advisor.policy.manage' => 'advisor',
            'advisor.bonus.read' => 'advisor',
            'advisor.bonus.submit' => 'advisor',
            'advisor.bonus.approve' => 'advisor',
            'advisor.bonus.override' => 'advisor',

            'identity.membership.manage' => 'identity',
            'security.audit.read' => 'security',
            'security.events.read' => 'security',
            'data.sensitive.read' => 'security',
            'platform.billing.manage' => 'platform',
            'billing.invoice.read' => 'billing',
            'billing.payment.report' => 'billing',
            'billing.subscription.read' => 'billing',
            'billing.usage.read' => 'billing',
            'billing.usage.write' => 'billing',
            'files.create' => 'files',
            'files.read' => 'files',
            'files.sensitive.create' => 'files',
            'files.sensitive.read' => 'files',
            'hr.department.read' => 'hr',
            'hr.department.manage' => 'hr',
            'hr.position.read' => 'hr',
            'hr.position.manage' => 'hr',
            'hr.employee.read' => 'hr',
            'hr.employee.create' => 'hr',
            'hr.employee.update' => 'hr',
            'hr.onboarding.read' => 'hr',
            'hr.onboarding.write' => 'hr',
            'hr.compensation.read' => 'hr',
            'hr.compensation.write' => 'hr',
            'hr.payroll.read' => 'hr',
            'hr.payroll.write' => 'hr',
            'hr.payroll.approve' => 'hr',
            'hr.payroll.disburse' => 'hr',
            'hr.attendance.read' => 'hr',
            'hr.attendance.write' => 'hr',
            'hr.leave.read' => 'hr',
            'hr.leave.create' => 'hr',
            'hr.leave.manage' => 'hr',
            'hr.leave.approve' => 'hr',
            'hr.performance.read' => 'hr',
            'hr.performance.write' => 'hr',
            'hr.performance.approve' => 'hr',
            'hr.recruitment.read' => 'hr',
            'hr.recruitment.write' => 'hr',
            'hr.employee_relations.read' => 'hr',
            'hr.employee_relations.write' => 'hr',
            'hr.employee_relations.close' => 'hr',
            'sales.customer.read' => 'sales',
            'sales.customer.create' => 'sales',
            'sales.customer.update' => 'sales',
            'sales.contact.read' => 'sales',
            'sales.contact.write' => 'sales',
            'sales.lead.read' => 'sales',
            'sales.lead.write' => 'sales',
            'sales.opportunity.read' => 'sales',
            'sales.opportunity.write' => 'sales',
            'sales.pipeline.manage' => 'sales',
            'sales.activity.read' => 'sales',
            'sales.activity.write' => 'sales',
            'sales.target.read' => 'sales',
            'sales.target.write' => 'sales',
            'sales.forecast.read' => 'sales',
            'sales.forecast.write' => 'sales',
            'ai.knowledge.read' => 'ai',
            'ai.knowledge.manage' => 'ai',
            'ai.knowledge.sensitive.read' => 'ai',
            'ai.ask' => 'ai',
            'ai.agent.manage' => 'ai',
        ] as $name => $module) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['module' => $module]);
        }
    }
}
