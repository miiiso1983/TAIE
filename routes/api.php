<?php

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\TenantFileController;
use App\Http\Middleware\RequirePlatformOwner;
use App\Models\Permission;
use App\Modules\Advisor\Http\Controllers\BonusController;
use App\Modules\Advisor\Http\Controllers\CompanyRuleController;
use App\Modules\Advisor\Http\Controllers\DataQualityController;
use App\Modules\Advisor\Http\Controllers\DecisionController;
use App\Modules\Advisor\Http\Controllers\ForecastEvaluationController;
use App\Modules\Advisor\Http\Controllers\ForecastScheduleController;
use App\Modules\Advisor\Http\Controllers\ImportController;
use App\Modules\Advisor\Http\Controllers\MarketContextController;
use App\Modules\Advisor\Http\Controllers\ProductForecastController;
use App\Modules\Advisor\Http\Controllers\ReportController;
use App\Modules\Advisor\Http\Controllers\SalesMetricsController;
use App\Modules\Advisor\Http\Controllers\SetupController;
use App\Modules\Advisor\Http\Controllers\SourceControlController;
use App\Modules\AI\Http\Controllers\AskController;
use App\Modules\AI\Http\Controllers\ConversationController;
use App\Modules\AI\Http\Controllers\ExternalReferenceController;
use App\Modules\AI\Http\Controllers\KnowledgeController;
use App\Modules\Billing\Http\Controllers\BillingController;
use App\Modules\Billing\Http\Controllers\ManualBillingController;
use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\HRWorkflowController;
use App\Modules\HR\Http\Controllers\LeaveAdminController;
use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\HR\Http\Controllers\OnboardingController;
use App\Modules\HR\Http\Controllers\PayrollController;
use App\Modules\HR\Http\Controllers\PositionController;
use App\Modules\Integrations\Http\Controllers\IntegrationController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Modules\Sales\Http\Controllers\CRMController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\OpportunityController;
use App\Modules\Sales\Http\Controllers\PipelineController;
use App\Modules\Sales\Http\Controllers\SalesOperationsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api', 'throttle:login'])->post('/auth/login', [AuthController::class, 'login']);
Route::middleware(['throttle:api', 'throttle:password-reset'])->group(function () {
    Route::post('/auth/forgot-password', [AuthController::class, 'sendPasswordResetLink']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware(['throttle:api', 'auth:sanctum'])->get('/platform/context', function (Request $request) {
    abort_unless($request->user()->status === 'active', 403);
    $owner = DB::table('platform_owners')->where('user_id', $request->user()->id)->exists();

    return response()->json(['data' => ['platform_owner' => $owner, 'user' => ['name' => $request->user()->name, 'email' => $request->user()->email]]]);
});
Route::middleware(['throttle:api', 'auth:sanctum', RequirePlatformOwner::class])->prefix('platform')->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
});

Route::middleware(['throttle:api', 'auth:sanctum', 'tenant.resolve', 'tenant.membership', 'tenant.active', 'billing.access'])->group(function () {

    Route::prefix('integrations')->middleware(['permission:integration.read', 'permission:data.sensitive.read'])->group(function () {
        Route::get('/catalog', [IntegrationController::class, 'catalog']);
        Route::get('/connections', [IntegrationController::class, 'index']);
        Route::post('/connections', [IntegrationController::class, 'store'])->middleware(['permission:integration.manage', 'idempotency']);
        Route::patch('/connections/{uuid}/status', [IntegrationController::class, 'status'])->middleware(['permission:integration.manage', 'idempotency']);
        Route::get('/connections/{uuid}/batches', [IntegrationController::class, 'batches']);
        Route::post('/connections/{uuid}/preview', [IntegrationController::class, 'preview'])->middleware(['permission:integration.sync', 'permission:data.import.manage', 'idempotency']);
    });

    Route::prefix('advisor/company-rules')->middleware(['permission:data.sensitive.read'])->group(function () {
        Route::get('/', [CompanyRuleController::class, 'index']);
        Route::post('/', [CompanyRuleController::class, 'store'])->middleware(['permission:advisor.rule.manage', 'idempotency']);
        Route::get('/{uuid}/simulations', [CompanyRuleController::class, 'simulations']);
        Route::post('/{uuid}/simulate', [CompanyRuleController::class, 'simulate'])->middleware(['permission:advisor.rule.manage', 'idempotency']);
        Route::post('/{uuid}/status', [CompanyRuleController::class, 'status'])->middleware(['permission:advisor.rule.approve', 'idempotency']);
    });

    Route::prefix('advisor/source-control')->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read'])->group(function () {
        Route::get('/authorities', [SourceControlController::class, 'index']);
        Route::get('/identities', [SourceControlController::class, 'links']);
        Route::get('/sources', [SourceControlController::class, 'sources']);
        Route::post('/authorities', [SourceControlController::class, 'authority'])->middleware(['permission:advisor.source.manage', 'idempotency']);
        Route::post('/identities', [SourceControlController::class, 'link'])->middleware(['permission:advisor.source.manage', 'idempotency']);
        Route::post('/identities/{uuid}/revoke', [SourceControlController::class, 'revoke'])->middleware(['permission:advisor.source.manage', 'idempotency']);
    });

    Route::prefix('advisor/decisions')->middleware(['permission:advisor.decision.read', 'permission:advisor.report.read', 'permission:data.sensitive.read'])->group(function () {
        Route::get('/owners', [DecisionController::class, 'owners'])->middleware('permission:advisor.decision.manage');
        Route::get('/', [DecisionController::class, 'index']);
        Route::get('/{uuid}', [DecisionController::class, 'show']);
        Route::post('/', [DecisionController::class, 'store'])->middleware(['permission:advisor.decision.manage', 'idempotency']);
        Route::post('/{uuid}/transition', [DecisionController::class, 'transition'])->middleware(['permission:advisor.decision.manage', 'idempotency']);
    });

    Route::prefix('advisor')->middleware(['permission:advisor.report.read', 'permission:data.sensitive.read'])->group(function () {
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{uuid}/explanations', [ReportController::class, 'explanations'])->middleware(['permission:advisor.report.explain', 'permission:ai.ask']);
        Route::post('/reports/{uuid}/explain', [ReportController::class, 'explain'])->middleware(['permission:advisor.report.explain', 'permission:ai.ask', 'idempotency', 'billing.ai-quota']);
        Route::get('/reports/{uuid}', [ReportController::class, 'show']);
        Route::post('/reports', [ReportController::class, 'generate'])->middleware(['permission:advisor.report.generate', 'idempotency']);
        Route::get('/report-schedules', [ReportController::class, 'schedules'])->middleware('permission:advisor.report.manage');
        Route::post('/report-schedules', [ReportController::class, 'createSchedule'])->middleware(['permission:advisor.report.manage', 'permission:advisor.report.generate', 'idempotency']);
        Route::post('/report-schedules/{uuid}/disable', [ReportController::class, 'disableSchedule'])->middleware(['permission:advisor.report.manage', 'idempotency']);
    });

    Route::prefix('advisor')->middleware(['permission:advisor.bonus.read', 'permission:data.sensitive.read', 'permission:hr.compensation.read'])->group(function () {
        Route::get('/bonus-policies', [BonusController::class, 'policies']);
        Route::get('/bonus-policies/{uuid}', [BonusController::class, 'policy']);
        Route::post('/bonus-policies', [BonusController::class, 'createPolicy'])->middleware(['permission:advisor.policy.manage', 'idempotency']);
        Route::get('/bonus-requests', [BonusController::class, 'index']);
        Route::get('/bonus-requests/{uuid}', [BonusController::class, 'show']);
        Route::post('/bonus-requests', [BonusController::class, 'submit'])->middleware(['permission:advisor.bonus.submit', 'idempotency']);
        Route::post('/bonus-requests/{uuid}/evaluate', [BonusController::class, 'refresh'])->middleware(['permission:advisor.bonus.approve', 'idempotency']);
        Route::post('/bonus-requests/{uuid}/decision', [BonusController::class, 'decide'])->middleware(['permission:advisor.bonus.approve', 'idempotency']);
    });

    Route::post('/advisor/sales/coverage', [SalesMetricsController::class, 'confirmCoverage'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:data.import.manage', 'idempotency']);
    Route::prefix('advisor/data')->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read'])->group(function () {
        Route::get('/catalog', [DataQualityController::class, 'catalog']);
        Route::get('/inventory', [DataQualityController::class, 'inventory']);
        Route::get('/geography', [DataQualityController::class, 'geography']);
        Route::get('/sources', [DataQualityController::class, 'sources']);
        Route::get('/reconciliations', [DataQualityController::class, 'index']);
        Route::get('/reconciliations/{uuid}', [DataQualityController::class, 'show']);
        Route::get('/reconciliations/{uuid}/differences', [DataQualityController::class, 'differences']);
        Route::post('/reconciliations/detailed', [DataQualityController::class, 'detailed'])->middleware(['permission:advisor.data.review', 'throttle:imports', 'idempotency']);
        Route::post('/reconciliations', [DataQualityController::class, 'store'])->middleware(['permission:advisor.data.review', 'idempotency']);
    });
    Route::get('/advisor/product-forecasts/{uuid}/evaluations', [ForecastEvaluationController::class, 'index'])->middleware(['permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::post('/advisor/product-forecasts/{uuid}/evaluations', [ForecastEvaluationController::class, 'store'])->middleware(['permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:advisor.data.review', 'idempotency', 'throttle:imports']);
    Route::get('/advisor/forecast-schedules', [ForecastScheduleController::class, 'index'])->middleware(['permission:advisor.forecast.manage', 'permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:advisor.data.review']);
    Route::post('/advisor/forecast-schedules', [ForecastScheduleController::class, 'store'])->middleware(['permission:advisor.forecast.manage', 'permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:advisor.data.review', 'idempotency']);
    Route::post('/advisor/forecast-schedules/{uuid}/review', [ForecastScheduleController::class, 'review'])->middleware(['permission:advisor.forecast.manage', 'permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:advisor.data.review', 'idempotency']);
    Route::post('/advisor/forecast-schedules/{uuid}/disable', [ForecastScheduleController::class, 'disable'])->middleware(['permission:advisor.forecast.manage', 'permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:advisor.data.review', 'idempotency']);
    Route::get('/advisor/product-forecasts', [ProductForecastController::class, 'index'])->middleware(['permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::get('/advisor/product-forecasts/{uuid}', [ProductForecastController::class, 'show'])->middleware(['permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::post('/advisor/product-forecast', [ProductForecastController::class, 'analyze'])->middleware(['permission:advisor.forecast.run', 'permission:advisor.sales.read', 'permission:data.sensitive.read', 'idempotency', 'throttle:imports']);
    Route::get('/advisor/setup', [SetupController::class, 'index'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::get('/advisor/sales/economic-comparison', [SalesMetricsController::class, 'economicComparison'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::get('/advisor/market-context', [MarketContextController::class, 'index'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::post('/advisor/market-context', [MarketContextController::class, 'store'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:advisor.market.manage', 'idempotency']);
    Route::post('/advisor/market-context/{uuid}/revoke', [MarketContextController::class, 'revoke'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read', 'permission:advisor.market.manage', 'idempotency']);
    Route::get('/advisor/sales/metrics', [SalesMetricsController::class, 'metrics'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::get('/advisor/sales/invoices', [SalesMetricsController::class, 'invoices'])->middleware(['permission:advisor.sales.read', 'permission:data.sensitive.read']);
    Route::prefix('advisor/imports')->middleware(['permission:data.import.manage', 'permission:data.sensitive.read'])->group(function () {
        Route::get('/schemas', [ImportController::class, 'schemas']);
        Route::get('/', [ImportController::class, 'index']);
        Route::post('/inspect', [ImportController::class, 'inspect'])->middleware('throttle:imports');
        Route::post('/preview', [ImportController::class, 'preview'])->middleware('throttle:imports');
        Route::post('/{uuid}/commit', [ImportController::class, 'commit'])->middleware(['idempotency', 'throttle:imports']);
    });

    Route::get('/identity/members', [MembershipController::class, 'index'])->middleware('permission:identity.membership.manage');
    Route::get('/identity/permissions', [MembershipController::class, 'permissions'])->middleware('permission:identity.membership.manage');
    Route::post('/identity/members', [MembershipController::class, 'store'])->middleware(['permission:identity.membership.manage', 'idempotency']);
    Route::patch('/identity/members/{uuid}', [MembershipController::class, 'update'])->middleware(['permission:identity.membership.manage', 'idempotency']);
    Route::patch('/identity/members/{uuid}/employee', [MembershipController::class, 'linkEmployee'])->middleware(['permission:identity.membership.manage', 'permission:hr.employee.update', 'idempotency']);
    Route::get('/billing/plans', [ManualBillingController::class, 'plans'])->middleware('permission:billing.subscription.read');
    Route::get('/billing/invoices', [ManualBillingController::class, 'invoices'])->middleware('permission:billing.invoice.read');
    Route::get('/billing/payments', [ManualBillingController::class, 'payments'])->middleware('permission:billing.invoice.read');
    Route::post('/billing/plans', [ManualBillingController::class, 'createPlan'])->middleware(['permission:platform.billing.manage', 'idempotency']);
    Route::post('/billing/invoices', [ManualBillingController::class, 'issue'])->middleware(['permission:platform.billing.manage', 'idempotency']);
    Route::post('/billing/invoices/{uuid}/payments', [ManualBillingController::class, 'report'])->middleware(['permission:billing.payment.report', 'idempotency']);
    Route::post('/billing/payments/{uuid}/decision', [ManualBillingController::class, 'decide'])->middleware(['permission:platform.billing.manage', 'idempotency']);
    Route::post('/billing/invoices/{uuid}/void', [ManualBillingController::class, 'void'])->middleware(['permission:platform.billing.manage', 'idempotency']);
    Route::get('/billing/subscription', [BillingController::class, 'subscription'])->middleware('permission:billing.subscription.read');
    Route::get('/billing/usage', [BillingController::class, 'usage'])->middleware('permission:billing.usage.read');
    Route::post('/billing/usage', [BillingController::class, 'recordUsage'])->middleware(['permission:billing.usage.write', 'idempotency']);
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:mfa');
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/mfa/setup', [AuthController::class, 'beginMfa'])->middleware('throttle:mfa');
    Route::post('/auth/mfa/confirm', [AuthController::class, 'confirmMfa'])->middleware('throttle:mfa');
    Route::get('/hr/departments', [DepartmentController::class, 'index'])->middleware('permission:hr.department.read');
    Route::post('/hr/departments', [DepartmentController::class, 'store'])->middleware(['permission:hr.department.manage', 'idempotency']);
    Route::get('/hr/employees', [EmployeeController::class, 'index'])->middleware('permission:hr.employee.read');
    Route::post('/hr/employees', [EmployeeController::class, 'store'])->middleware(['permission:hr.employee.create', 'idempotency']);
    Route::patch('/hr/employees/{uuid}', [EmployeeController::class, 'update'])->middleware(['permission:hr.employee.update', 'idempotency']);
    Route::get('/hr/employees/{uuid}', [EmployeeController::class, 'show'])->middleware('permission:hr.employee.read');
    Route::get('/hr/employees/{employeeUuid}/onboarding-tasks', [OnboardingController::class, 'index'])->middleware('permission:hr.onboarding.read');
    Route::post('/hr/employees/{employeeUuid}/onboarding-tasks', [OnboardingController::class, 'store'])->middleware(['permission:hr.onboarding.write', 'idempotency']);
    Route::patch('/hr/onboarding-tasks/{uuid}', [OnboardingController::class, 'update'])->middleware(['permission:hr.onboarding.write', 'idempotency']);
    Route::get('/hr/payroll/runs', [PayrollController::class, 'index'])->middleware(['permission:hr.payroll.read', 'permission:data.sensitive.read']);
    Route::post('/hr/payroll/runs', [PayrollController::class, 'store'])->middleware(['permission:hr.payroll.write', 'permission:hr.compensation.read', 'permission:data.sensitive.read', 'idempotency']);
    Route::get('/hr/payroll/runs/{uuid}', [PayrollController::class, 'show'])->middleware(['permission:hr.payroll.read', 'permission:data.sensitive.read']);
    Route::patch('/hr/payroll/runs/{runUuid}/items/{itemUuid}', [PayrollController::class, 'updateItem'])->middleware(['permission:hr.payroll.write', 'permission:data.sensitive.read', 'idempotency']);
    Route::post('/hr/payroll/runs/{uuid}/submit', [PayrollController::class, 'submit'])->middleware(['permission:hr.payroll.write', 'permission:data.sensitive.read', 'idempotency']);
    Route::post('/hr/payroll/runs/{uuid}/return', [PayrollController::class, 'returnForRevision'])->middleware(['permission:hr.payroll.approve', 'permission:data.sensitive.read', 'idempotency']);
    Route::post('/hr/payroll/runs/{uuid}/approve', [PayrollController::class, 'approve'])->middleware(['permission:hr.payroll.approve', 'permission:data.sensitive.read', 'idempotency']);
    Route::post('/hr/payroll/runs/{uuid}/mark-paid', [PayrollController::class, 'markPaid'])->middleware(['permission:hr.payroll.disburse', 'permission:data.sensitive.read', 'idempotency']);
    Route::get('/hr/positions', [PositionController::class, 'index'])->middleware('permission:hr.position.read');
    Route::post('/hr/positions', [PositionController::class, 'store'])->middleware(['permission:hr.position.manage', 'idempotency']);
    Route::get('/hr/leave-types', [LeaveAdminController::class, 'indexTypes'])->middleware('permission:hr.leave.read');
    Route::post('/hr/leave-types', [LeaveAdminController::class, 'createType'])->middleware(['permission:hr.leave.manage', 'idempotency']);
    Route::get('/hr/leave-balances', [LeaveAdminController::class, 'indexBalances'])->middleware('permission:hr.leave.read');
    Route::put('/hr/leave-balances', [LeaveAdminController::class, 'setBalance'])->middleware(['permission:hr.leave.manage', 'idempotency']);
    Route::get('/hr/leave-requests', [LeaveRequestController::class, 'index'])->middleware('permission:hr.leave.read');
    Route::post('/hr/leave-requests', [LeaveRequestController::class, 'store'])->middleware(['permission:hr.leave.create', 'idempotency']);
    Route::get('/hr/employees/{uuid}/compensation', [HRWorkflowController::class, 'compensation'])->middleware(['permission:hr.compensation.read', 'permission:data.sensitive.read']);
    Route::post('/hr/employees/{uuid}/compensation', [HRWorkflowController::class, 'createCompensation'])->middleware(['permission:hr.compensation.write', 'idempotency']);
    Route::get('/hr/attendance', [HRWorkflowController::class, 'attendance'])->middleware('permission:hr.attendance.read');
    Route::post('/hr/attendance', [HRWorkflowController::class, 'recordAttendance'])->middleware(['permission:hr.attendance.write', 'idempotency']);
    Route::get('/hr/performance/cycles', [HRWorkflowController::class, 'cycles'])->middleware('permission:hr.performance.read');
    Route::post('/hr/performance/cycles', [HRWorkflowController::class, 'createCycle'])->middleware(['permission:hr.performance.write', 'idempotency']);
    Route::patch('/hr/performance/cycles/{uuid}/status', [HRWorkflowController::class, 'transitionCycle'])->middleware(['permission:hr.performance.write', 'idempotency']);
    Route::get('/hr/performance/goals', [HRWorkflowController::class, 'goals'])->middleware('permission:hr.performance.read');
    Route::post('/hr/performance/goals', [HRWorkflowController::class, 'createGoal'])->middleware(['permission:hr.performance.write', 'idempotency']);
    Route::get('/hr/performance/reviews', [HRWorkflowController::class, 'reviews'])->middleware(['permission:hr.performance.read', 'permission:data.sensitive.read']);
    Route::post('/hr/performance/reviews', [HRWorkflowController::class, 'createReview'])->middleware(['permission:hr.performance.write', 'idempotency']);
    Route::post('/hr/performance/reviews/{uuid}/decision', [HRWorkflowController::class, 'decideReview'])->middleware(['permission:hr.performance.approve', 'permission:data.sensitive.read']);
    Route::patch('/hr/performance/reviews/{uuid}', [HRWorkflowController::class, 'resubmitReview'])->middleware(['permission:hr.performance.write', 'permission:data.sensitive.read']);
    Route::get('/hr/requisitions', [HRWorkflowController::class, 'requisitions'])->middleware('permission:hr.recruitment.read');
    Route::post('/hr/requisitions', [HRWorkflowController::class, 'createRequisition'])->middleware(['permission:hr.recruitment.write', 'idempotency']);
    Route::get('/hr/candidates', [HRWorkflowController::class, 'candidates'])->middleware(['permission:hr.recruitment.read', 'permission:data.sensitive.read']);
    Route::post('/hr/candidates', [HRWorkflowController::class, 'createCandidate'])->middleware(['permission:hr.recruitment.write', 'idempotency']);
    Route::get('/hr/applications', [HRWorkflowController::class, 'applications'])->middleware(['permission:hr.recruitment.read', 'permission:data.sensitive.read']);
    Route::post('/hr/applications', [HRWorkflowController::class, 'createApplication'])->middleware(['permission:hr.recruitment.write', 'idempotency']);
    Route::patch('/hr/applications/{uuid}/status', [HRWorkflowController::class, 'transitionApplication'])->middleware(['permission:hr.recruitment.write', 'idempotency']);
    Route::post('/hr/applications/{uuid}/employee', [HRWorkflowController::class, 'createEmployeeFromHiredApplication'])->middleware(['permission:hr.recruitment.write', 'permission:hr.employee.create']);
    Route::get('/hr/applications/{applicationUuid}/interviews', [HRWorkflowController::class, 'interviews'])->middleware(['permission:hr.recruitment.read', 'permission:data.sensitive.read']);
    Route::post('/hr/applications/{applicationUuid}/interviews', [HRWorkflowController::class, 'scheduleInterview'])->middleware(['permission:hr.recruitment.write', 'idempotency']);
    Route::patch('/hr/interviews/{uuid}', [HRWorkflowController::class, 'completeInterview'])->middleware(['permission:hr.recruitment.write', 'idempotency']);
    Route::get('/hr/employee-relations/cases', [HRWorkflowController::class, 'relationsCases'])->middleware(['permission:hr.employee_relations.read', 'permission:data.sensitive.read']);
    Route::post('/hr/employee-relations/cases', [HRWorkflowController::class, 'createRelationsCase'])->middleware(['permission:hr.employee_relations.write', 'idempotency']);
    Route::patch('/hr/employee-relations/cases/{uuid}', [HRWorkflowController::class, 'updateRelationsCase'])->middleware(['permission:hr.employee_relations.close', 'idempotency']);
    Route::post('/hr/leave-requests/{uuid}/decision', [LeaveRequestController::class, 'decide'])->middleware(['permission:hr.leave.approve', 'idempotency']);

    Route::get('/sales/customers', [CustomerController::class, 'index'])->middleware('permission:sales.customer.read');
    Route::patch('/sales/customers/{uuid}', [CustomerController::class, 'update'])->middleware(['permission:sales.customer.update', 'idempotency']);
    Route::post('/sales/customers', [CustomerController::class, 'store'])->middleware(['permission:sales.customer.create', 'idempotency']);
    Route::get('/sales/leads', [CRMController::class, 'leads'])->middleware('permission:sales.lead.read');
    Route::post('/sales/leads', [CRMController::class, 'createLead'])->middleware(['permission:sales.lead.write', 'idempotency']);
    Route::get('/sales/customers/{customerUuid}/contacts', [CRMController::class, 'contacts'])->middleware('permission:sales.contact.read');
    Route::post('/sales/customers/{customerUuid}/contacts', [CRMController::class, 'createContact'])->middleware(['permission:sales.contact.write', 'idempotency']);
    Route::get('/sales/pipelines', [PipelineController::class, 'index'])->middleware('permission:sales.opportunity.read');
    Route::post('/sales/pipelines', [PipelineController::class, 'store'])->middleware(['permission:sales.pipeline.manage', 'idempotency']);
    Route::get('/sales/opportunities', [OpportunityController::class, 'index'])->middleware('permission:sales.opportunity.read');
    Route::post('/sales/opportunities', [OpportunityController::class, 'store'])->middleware(['permission:sales.opportunity.write', 'idempotency']);
    Route::get('/sales/opportunities/{uuid}', [OpportunityController::class, 'show'])->middleware('permission:sales.opportunity.read');
    Route::get('/sales/activities', [SalesOperationsController::class, 'activities'])->middleware('permission:sales.activity.read');
    Route::post('/sales/activities', [SalesOperationsController::class, 'createActivity'])->middleware(['permission:sales.activity.write', 'idempotency']);
    Route::patch('/sales/activities/{uuid}', [SalesOperationsController::class, 'completeActivity'])->middleware(['permission:sales.activity.write', 'idempotency']);
    Route::get('/sales/targets', [SalesOperationsController::class, 'targets'])->middleware('permission:sales.target.read');
    Route::post('/sales/targets', [SalesOperationsController::class, 'createTarget'])->middleware(['permission:sales.target.write', 'idempotency']);
    Route::patch('/sales/targets/{uuid}', [SalesOperationsController::class, 'updateTarget'])->middleware(['permission:sales.target.write', 'idempotency']);
    Route::get('/sales/forecasts', [SalesOperationsController::class, 'forecasts'])->middleware('permission:sales.forecast.read');
    Route::post('/sales/forecasts/generate', [SalesOperationsController::class, 'generateForecast'])->middleware(['permission:sales.forecast.write', 'idempotency']);
    Route::post('/sales/opportunities/{uuid}/stage', [OpportunityController::class, 'move'])->middleware(['permission:sales.opportunity.write', 'idempotency']);

    Route::get('/knowledge/external-references', [ExternalReferenceController::class, 'index'])->middleware('permission:ai.knowledge.read');
    Route::patch('/knowledge/business-activity', [ExternalReferenceController::class, 'activity'])->middleware(['permission:ai.knowledge.manage', 'idempotency']);
    Route::post('/knowledge/external-references/{key}/activate', [ExternalReferenceController::class, 'activate'])->middleware(['permission:ai.knowledge.manage', 'idempotency']);
    Route::post('/knowledge/external-references/{key}/disable', [ExternalReferenceController::class, 'disable'])->middleware(['permission:ai.knowledge.manage', 'idempotency']);

    Route::get('/knowledge/sources', [KnowledgeController::class, 'sources'])->middleware('permission:ai.knowledge.read');
    Route::post('/knowledge/sources', [KnowledgeController::class, 'createSource'])->middleware(['permission:ai.knowledge.manage', 'idempotency']);
    Route::post('/knowledge/sources/{sourceUuid}/documents', [KnowledgeController::class, 'createDocument'])->middleware(['permission:ai.knowledge.manage', 'idempotency']);
    Route::get('/knowledge/sources/{sourceUuid}/documents', [KnowledgeController::class, 'documents'])->middleware('permission:ai.knowledge.read');
    Route::post('/knowledge/documents/{uuid}/retry', [KnowledgeController::class, 'retry'])->middleware(['permission:ai.knowledge.manage', 'idempotency']);
    Route::get('/knowledge/documents/{uuid}', [KnowledgeController::class, 'document'])->middleware('permission:ai.knowledge.read');
    Route::post('/ai/agents', [KnowledgeController::class, 'createAgent'])->middleware(['permission:ai.agent.manage', 'idempotency']);
    Route::get('/ai/conversations', [ConversationController::class, 'index'])->middleware('permission:ai.ask');
    Route::get('/ai/conversations/{uuid}/messages', [ConversationController::class, 'messages'])->middleware('permission:ai.ask');
    Route::post('/ai/ask', AskController::class)->middleware(['permission:ai.ask', 'throttle:api', 'idempotency', 'billing.ai-quota']);

    Route::post('/files', [TenantFileController::class, 'store'])->middleware(['permission:files.create', 'idempotency']);
    Route::get('/files/{uuid}', [TenantFileController::class, 'show'])->middleware('permission:files.read');
    Route::get('/tenant/context', function (Request $request, TenantContext $context) {
        $tenant = $context->requireTenant();

        return response()->json(['success' => true, 'data' => ['tenant' => ['uuid' => $tenant->uuid, 'name' => $tenant->name, 'timezone' => $tenant->timezone ?: 'UTC'], 'user' => ['uuid' => $request->user()->uuid, 'mfa_enabled' => $request->user()->mfaCredentials()->whereNotNull('verified_at')->exists()], 'permissions' => Permission::query()->pluck('name')->filter(fn ($name) => $request->user()->hasTenantPermission($tenant->id, $name))->values()]]);
    });
});
