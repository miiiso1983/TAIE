<?php

namespace App\Providers;

use App\Core\AI\OpenAICompatibleProvider;
use App\Core\Authentication\TotpMfaProvider;
use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Contracts\EmbeddingProviderInterface;
use App\Core\Contracts\LLMProviderInterface;
use App\Core\Contracts\MFAProviderInterface;
use App\Core\Logging\AuditTrailService;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(AuditLoggerInterface::class, AuditTrailService::class);
        $this->app->bind(MFAProviderInterface::class, TotpMfaProvider::class);
        $this->app->bind(EmbeddingProviderInterface::class, OpenAICompatibleProvider::class);
        $this->app->bind(LLMProviderInterface::class, OpenAICompatibleProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(DiagnosingHealth::class, function () {
            DB::select('SELECT 1');
            Cache::get('teami.health');
        });
        RateLimiter::for('imports', fn (Request $request) => Limit::perMinute(10)->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.($request->header('X-Tenant') ?? '')));
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('mfa', fn ($request) => Limit::perMinute(5)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('password-reset', fn ($request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
