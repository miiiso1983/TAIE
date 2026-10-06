<?php

namespace App\Modules\Advisor\Services;

use App\Core\Events\Outbox;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\AdvisorReport;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportService
{
    public static function permissions(string $kind): array
    {
        return $kind === 'sales' ? ['advisor.sales.read', 'data.sensitive.read'] : ['hr.employee.read', 'hr.leave.read', 'hr.attendance.read', 'data.sensitive.read'];
    }

    public static function allowed(User $u, string $kind): bool
    {
        foreach (self::permissions($kind) as $permission) {
            if (! $u->hasTenantPermission(app(TenantContext::class)->getTenantId(), $permission)) {
                return false;
            }
        }

        return true;
    }

    public function generate(array $d, User $user): AdvisorReport
    {
        return DB::transaction(function () use ($d, $user) {
            Tenant::query()->whereKey(app(TenantContext::class)->getTenantId())->lockForUpdate()->firstOrFail();
            abort_unless(self::allowed($user, $d['kind']), 403, 'You cannot generate this report scope.');
            $latest = AdvisorReport::query()->where('kind', $d['kind'])->whereDate('period_from', $d['from'])->whereDate('period_to', $d['to'])->whereDate('as_of', $d['as_of'])->latest('version')->first();
            if ($latest && ! ($d['new_version'] ?? false)) {
                return $latest;
            }
            $snapshot = $d['kind'] === 'sales' ? $this->sales($d) : $this->hr($d);
            $hash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($latest && hash_equals($latest->fingerprint, $hash)) {
                return $latest;
            }
            $report = AdvisorReport::query()->create(['uuid' => Str::uuid(), 'kind' => $d['kind'], 'period_from' => $d['from'], 'period_to' => $d['to'], 'as_of' => $d['as_of'], 'version' => ($latest?->version ?? 0) + 1, 'fingerprint' => $hash, 'snapshot_encrypted' => $snapshot, 'created_by' => $user->id]);
            app(AuditTrailService::class)->record('advisor.report.generated', ['user_id' => $user->id, 'module' => 'advisor', 'entity_type' => 'advisor_report', 'entity_id' => $report->uuid, 'new_values' => ['kind' => $report->kind, 'version' => $report->version]]);
            app(Outbox::class)->append('advisor.report.generated', ['report_uuid' => $report->uuid, 'kind' => $report->kind]);

            return $report;
        });
    }

    private function sales(array $d): array
    {
        $current = app(SalesMetricsService::class)->dataset($d);
        $days = (int) CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) + 1;
        $previousTo = CarbonImmutable::parse($d['from'])->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $previous = app(SalesMetricsService::class)->dataset(['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString(), 'as_of' => $previousTo->toDateString()]);
        $totals = function ($rows) {
            $groups = [];
            foreach ($rows as $r) {
                $groups[$r['currency']] ??= array_fill_keys(['sales_minor', 'returns_minor', 'net_sales_minor', 'collected_minor', 'balance_minor', 'overdue_balance_minor', 'credit_minor'], '0');
                foreach ($groups[$r['currency']] as $key => $value) {
                    $groups[$r['currency']][$key] = bcadd($value, $r[$key], 0);
                }
            }

            return $groups;
        };

        return ['engine_version' => 1, 'kind' => 'sales', 'filters' => array_intersect_key($d, array_flip(['from', 'to', 'as_of'])), 'totals' => $totals($current['rows']), 'rows' => $current['rows'], 'evidence' => $current['evidence'], 'quality' => $current['quality'],
            'previous' => ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString(), 'as_of' => $previousTo->toDateString(), 'totals' => $totals($previous['rows'])],
            'notes' => ['المقارنة مع فترة سابقة مساوية بعدد الأيام، وتحصيلاتها حتى نهايتها فقط. إذا امتد تاريخ قياس الفترة الحالية بعد نهايتها، تختلف نافذة التحصيل بين الفترتين.', 'العملات مستقلة والبيانات غير مؤكدة التغطية. التقرير نسخة محفوظة ولا يتغير مع الاستيراد التالي.']];
    }

    private function hr(array $d): array
    {
        $attendance = AttendanceRecord::query()->whereDate('work_date', '>=', $d['from'])->whereDate('work_date', '<=', $d['to']);
        $leaves = LeaveRequest::query()->whereDate('starts_on', '<=', $d['to'])->whereDate('ends_on', '>=', $d['from']);

        return ['engine_version' => 1, 'kind' => 'hr', 'filters' => array_intersect_key($d, array_flip(['from', 'to', 'as_of'])),
            'headcount_current' => Employee::query()->selectRaw('employment_status, COUNT(*) AS total')->groupBy('employment_status')->pluck('total', 'employment_status')->all(),
            'attendance' => ['record_count' => (clone $attendance)->count(), 'employees_with_records' => (clone $attendance)->distinct()->count('employee_id'), 'missing_check_out' => (clone $attendance)->whereNotNull('check_in_at')->whereNull('check_out_at')->count()],
            'leave_requests_by_current_status' => (clone $leaves)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
            'coverage_confirmed' => false, 'notes' => ['عدد الموظفين وحالات الإجازات حالية وقت التوليد وليست إعادة بناء تاريخية عند as_of.', 'الحضور وطلبات الإجازة تُختار بتواريخ الفترة؛ السجل الناقص لا يثبت الغياب أو المخالفة. لا توجد ساعات متوقعة أو خصومات أو أحكام على الأفراد.', 'لا تُعرض الرواتب أو الحالات الطبية أو تفاصيل ملفات علاقات الموظفين في هذا التقرير.']];
    }

    public static function nextRun(string $frequency, int $hour, CarbonImmutable $local): CarbonImmutable
    {
        $candidate = match ($frequency) {
            'daily' => $local->startOfDay()->setHour($hour),'weekly' => $local->startOfWeek()->setHour($hour),'monthly' => $local->startOfMonth()->setHour($hour)
        };
        if ($candidate <= $local) {
            $candidate = match ($frequency) {
                'daily' => $candidate->addDay(),'weekly' => $candidate->addWeek(),'monthly' => $candidate->addMonth()
            };
        }

        return $candidate->utc();
    }

    public static function completedPeriod(string $frequency, CarbonImmutable $local): array
    {
        $to = match ($frequency) {
            'daily' => $local->subDay()->startOfDay(),'weekly' => $local->startOfWeek()->subDay(),'monthly' => $local->startOfMonth()->subDay()
        };
        $from = match ($frequency) {
            'daily' => $to,'weekly' => $to->startOfWeek(),'monthly' => $to->startOfMonth()
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'as_of' => $to->toDateString()];
    }
}
