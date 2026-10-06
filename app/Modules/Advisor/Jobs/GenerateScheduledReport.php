<?php

namespace App\Modules\Advisor\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Jobs\TenantAwareJob;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\ReportSchedule;
use App\Modules\Advisor\Services\ReportService;
use App\Modules\Billing\Services\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

class GenerateScheduledReport extends TenantAwareJob implements ShouldBeUnique
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
            $s = ReportSchedule::query()->whereKey($this->scheduleId)->lockForUpdate()->first();
            if (! $s || ! $s->enabled || $s->next_run_at->isFuture()) {
                return;
            }
            $owner = User::query()->whereKey($s->owner_id)->where('status', 'active')->first();
            $member = $tenant->users()->where('users.id', $s->owner_id)->wherePivot('status', 'active')->exists();
            if (! $owner || ! $member || ! $owner->hasTenantPermission($tenant->id, 'advisor.report.generate') || ! $owner->hasTenantPermission($tenant->id, 'advisor.report.manage') || ! $owner->hasTenantPermission($tenant->id, 'advisor.report.read') || ! ReportService::allowed($owner, $s->kind)) {
                $s->update(['enabled' => false, 'last_error_code' => 'permission_revoked']);

                return;
            }
            if (config('teami.billing_enforce') && ! app(BillingService::class)->entitledSubscription()) {
                $s->update(['enabled' => false, 'last_error_code' => 'subscription_required']);

                return;
            }
            $local = CarbonImmutable::now($tenant->timezone ?: 'UTC');
            app(ReportService::class)->generate(['kind' => $s->kind, ...ReportService::completedPeriod($s->frequency, $local)], $owner);
            $s->update(['last_run_at' => now(), 'last_error_code' => null, 'next_run_at' => ReportService::nextRun($s->frequency, $s->local_hour, $local)]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        ReportSchedule::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereKey($this->scheduleId)->update(['enabled' => false, 'last_error_code' => 'generation_failed']);
    }
}
