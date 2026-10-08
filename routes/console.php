<?php

use App\Jobs\PublishOutboxEvents;
use App\Jobs\RuntimeHeartbeat;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use App\Modules\Advisor\Jobs\GenerateScheduledReport;
use App\Modules\Advisor\Jobs\PrepareScheduledForecast;
use App\Modules\Advisor\Models\ForecastSchedule;
use App\Modules\Advisor\Models\ImportBatch;
use App\Modules\Advisor\Models\ReportSchedule;
use App\Modules\Billing\Models\TenantSubscription;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PublishOutboxEvents, 'critical')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    Cache::put('teami.scheduler_heartbeat', now()->timestamp, 300);
    RuntimeHeartbeat::dispatch();
})->everyMinute();
Schedule::call(function () {
    TenantSubscription::withoutGlobalScopes()->whereIn('status', ['active', 'trialing'])->where('ends_at', '<=', now())->update(['status' => 'expired']);
    IdempotencyKey::query()->where('expires_at', '<', now())->delete();
})->name('teami-maintenance')->hourly()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::call(function () {
    ReportSchedule::withoutGlobalScopes()->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))->where('enabled', true)->where('next_run_at', '<=', now())->orderBy('id')->limit(100)->get()->each(function ($s) {
        GenerateScheduledReport::dispatch($s->tenant_id, $s->id);
    });
})->name('advisor-report-dispatch')->everyMinute()->withoutOverlapping();
Schedule::call(function () {
    ImportBatch::withoutGlobalScopes()->whereIn('status', ['invalid', 'validated'])->where('expires_at', '<=', now())->update(['status' => 'expired', 'payload_encrypted' => null, 'errors_encrypted' => null]);
})->name('advisor-import-expiry')->hourly()->withoutOverlapping();

Schedule::call(function () {
    if (! Schema::hasTable('advisor_forecast_schedules')) {
        return;
    }
    ForecastSchedule::withoutGlobalScopes()->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))->where('enabled', true)->where('next_run_at', '<=', now())->orderBy('id')->limit(100)->get()->each(function ($s) {
        PrepareScheduledForecast::dispatch($s->tenant_id, $s->id);
    });
})->name('advisor-forecast-review-dispatch')->everyMinute()->withoutOverlapping();
