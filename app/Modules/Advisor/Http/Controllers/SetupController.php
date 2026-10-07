<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Advisor\Models\AdvisorReport;
use App\Modules\Advisor\Models\Area;
use App\Modules\Advisor\Models\CompanyRule;
use App\Modules\Advisor\Models\Decision;
use App\Modules\Advisor\Models\InventoryObservation;
use App\Modules\Advisor\Models\InvoiceLine;
use App\Modules\Advisor\Models\Product;
use App\Modules\Advisor\Models\Reconciliation;
use App\Modules\Advisor\Models\SalesInvoice;
use App\Modules\Advisor\Models\SourceAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SetupController extends Controller
{
    public function index(Request $r)
    {
        $steps = [];
        $items = [
            ['invoices', 'الفواتير والتحصيل', SalesInvoice::class, 'advisor_sales_invoices', 'imports', 'data.import.manage'],
            ['products', 'المنتجات ووحداتها', Product::class, 'advisor_products', 'imports', 'data.import.manage'],
            ['areas', 'مناطق الشركة', Area::class, 'advisor_areas', 'imports', 'data.import.manage'],
            ['lines', 'سطور الفواتير', InvoiceLine::class, 'advisor_invoice_lines', 'imports', 'data.import.manage'],
            ['inventory', 'المخزون والتكلفة والانقطاع', InventoryObservation::class, 'advisor_inventory_observations', 'imports', 'data.import.manage'],
            ['reconciliation', 'مطابقة المصدر', Reconciliation::class, 'advisor_reconciliations', 'analytics', 'advisor.sales.read']];
        foreach ($items as [$key,$label,$model,$table,$page,$permission]) {
            $installed = Schema::hasTable($table);
            $count = $installed ? $model::query()->count() : null;
            $steps[] = ['key' => $key, 'label' => $label, 'status' => ! $installed ? 'upgrade_required' : ($count > 0 ? 'records_present' : 'no_records'), 'count' => $count, 'page' => $page, 'permission' => $permission];
        }
        $tenant = app(TenantContext::class)->getTenantId();
        $rights = fn ($p) => $r->user()->hasTenantPermission($tenant, $p);
        $optional = [['reports', 'تقارير الفترة', AdvisorReport::class, 'advisor_reports', 'reports', 'advisor.report.read'], ['decisions', 'متابعة قرارات المدير', Decision::class, 'advisor_decisions', 'decisions', 'advisor.decision.read'], ['sources', 'مراجعة مصادر الحقيقة والهويات', SourceAuthority::class, 'advisor_source_authorities', 'sources', 'advisor.source.manage'], ['rules', 'محاكاة السياسات واعتمادها', CompanyRule::class, 'advisor_company_rules', 'rules', 'advisor.rule.manage']];
        foreach ($optional as [$key,$label,$model,$table,$page,$permission]) {
            if (! $rights($permission)) {
                continue;
            }
            $installed = Schema::hasTable($table);
            $steps[] = ['key' => $key, 'label' => $label, 'status' => $installed ? 'review_required' : 'upgrade_required', 'count' => null, 'page' => $page, 'permission' => $permission];
        }

        return response()->json(['data' => ['steps' => $steps, 'notice' => 'وجود سجلات لا يؤكد اكتمال البيانات أو قبول الإنتاج. طابق المصدر والتغطية والعملات والقيود قبل التوصيات.', 'operational_steps' => ['اختر مسؤول البيانات ومصدر الحقيقة لكل مجموعة قبل الاستيراد.', 'وحّد رموز المنتجات والمناطق ووحدة الكمية، ثم راجع سطور الفواتير والمرتجعات.', 'طابق المجاميع والسجلات مع تقرير مستقل وأقرّ نطاق التغطية بعد المراجعة.', 'اختبر سياسة المكافآت بمدخلات معروفة، وحدد المعتمدين المستقلين.', 'قارن التوصيات والتوقع التجريبي بمراجعة بشرية قبل تجربة تشغيل محدودة.', 'ترقية المخطط ومنح الصلاحيات واستعادة النسخ تحتاج مسؤول البيئة؛ لا تنفذها هذه الشاشة.']]]);
    }
}
