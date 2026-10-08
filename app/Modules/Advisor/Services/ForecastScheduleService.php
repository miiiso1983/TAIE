<?php

namespace App\Modules\Advisor\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Models\ForecastSchedule;
use App\Modules\Billing\Services\BillingService;
use Carbon\CarbonImmutable;

class ForecastScheduleService
{
    public function ownerAllowed(Tenant $tenant, ForecastSchedule $s): bool
    {
        $owner = User::query()->whereKey($s->owner_id)->where('status', 'active')->first();
        if (! $owner || ! $tenant->users()->where('users.id', $s->owner_id)->wherePivot('status', 'active')->exists()) {
            return false;
        }
        foreach (['advisor.forecast.manage', 'advisor.forecast.run', 'advisor.sales.read', 'data.sensitive.read', 'advisor.data.review'] as $permission) {
            if (! $owner->hasTenantPermission($tenant->id, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function entitled(): bool
    {
        return ! config('teami.billing_enforce') || (bool) app(BillingService::class)->entitledSubscription();
    }

    public function period(int $weeks, CarbonImmutable $local): array
    {
        $to = $local->startOfWeek()->subDay();

        return ['review_from' => $to->subWeeks($weeks - 1)->startOfWeek()->toDateString(), 'review_to' => $to->toDateString()];
    }
}
