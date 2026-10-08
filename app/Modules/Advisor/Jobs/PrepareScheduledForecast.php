<?php

namespace App\Modules\Advisor\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Jobs\TenantAwareJob;
use App\Models\Tenant;
use App\Modules\Advisor\Models\ForecastSchedule;
use App\Modules\Advisor\Services\ForecastScheduleService;
use App\Modules\Advisor\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

class PrepareScheduledForecast extends TenantAwareJob implements ShouldBeUnique
{
    public int $uniqueFor = 300;

    public function __construct(int $tenantId, public readonly int $scheduleId)
    {
        parent::__construct($tenantId);
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->scheduleId;
    }

    protected function handleForTenant(): void
    {
        DB::transaction(function () {
            $tenant = app(TenantContext::class)->requireTenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $s = ForecastSchedule::query()->whereKey($this->scheduleId)->lockForUpdate()->first();
            if (! $s || ! $s->enabled || $s->next_run_at->isFuture()) {
                return;
            }
            $service = app(ForecastScheduleService::class);
            if (! $service->ownerAllowed($tenant, $s) || ! $service->entitled()) {
                $s->update(['enabled' => false, 'last_error_code' => 'permission_or_subscription_revoked', 'version' => $s->version + 1]);

                return;
            }
            $local = CarbonImmutable::now($tenant->timezone ?: 'UTC');
            $s->update([...$service->period($s->history_weeks, $local), 'review_pending' => true, 'version' => $s->version + 1, 'next_run_at' => ReportService::nextRun('weekly', $s->local_hour, $local), 'last_error_code' => null]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        ForecastSchedule::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereKey($this->scheduleId)->update(['enabled' => false, 'last_error_code' => 'preparation_failed']);
    }
}
