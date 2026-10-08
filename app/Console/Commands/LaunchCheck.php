<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LaunchCheck extends Command
{
    protected $signature = 'teami:launch:check {--runtime : Require recent worker and scheduler heartbeats} {--ai : Require configured AI provider}';

    protected $description = 'Read-only production-readiness checks; never prints secrets.';

    public function handle(): int
    {
        $checks = [
            'PHP 8.4.1+' => version_compare(PHP_VERSION, '8.4.1', '>='),
            'Production environment' => app()->environment('production'),
            'Debug disabled' => ! config('app.debug'),
            'HTTPS application URL' => str_starts_with((string) config('app.url'), 'https://'),
            'Application key set' => filled(config('app.key')),
            'Audit HMAC secret set' => strlen((string) config('teami.audit_hmac_key')) >= 32,
            'MySQL production database' => config('database.default') === 'mysql',
            'Durable cache' => ! in_array(config('cache.default'), ['array', 'null']),
            'Durable queue' => ! in_array(config('queue.default'), ['sync', 'null']),
            'Real mail transport' => ! in_array(config('mail.default'), ['log', 'array']),
            'Billing enforcement enabled' => (bool) config('teami.billing_enforce'),
            'Bounded API token lifetime' => config('sanctum.expiration') > 0 && config('sanctum.expiration') <= 1440,
            'Production assets built' => is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot')),
            'Writable private storage' => is_writable(storage_path('app')) && is_writable(storage_path('logs')) && is_writable(base_path('bootstrap/cache')),
        ];
        try {
            DB::select('SELECT 1');
            $checks['Database reachable'] = true;
            $checks['Billing migrations applied'] = Schema::hasTable('billing_payments');
            foreach (['advisor_coverage_confirmations', 'advisor_products', 'advisor_areas', 'advisor_invoice_lines', 'advisor_reconciliations', 'advisor_decisions', 'advisor_decision_events', 'advisor_inventory_observations', 'advisor_bonus_approvals', 'advisor_product_forecast_runs', 'advisor_source_authorities', 'advisor_identity_links', 'advisor_company_rules', 'advisor_rule_simulations', 'advisor_forecast_evaluations', 'advisor_forecast_schedules'] as $table) {
                $checks['Schema '.$table] = Schema::hasTable($table);
            }
            $checks['Reconciliation method installed'] = Schema::hasColumn('advisor_reconciliations', 'method');
            $checks['Forecast decision evidence installed'] = Schema::hasColumn('advisor_decisions', 'forecast_run_id');
            $checks['Policy review history installed'] = Schema::hasColumn('advisor_company_rules', 'status_history_encrypted');
            $checks['Invoice area installed'] = Schema::hasColumn('advisor_sales_invoices', 'area_id');
            foreach (['advisor.decision.read', 'advisor.decision.manage', 'advisor.forecast.run', 'advisor.forecast.manage', 'advisor.bonus.approve.stage2', 'advisor.bonus.approve.stage3', 'advisor.data.review', 'data.catalog.import', 'advisor.source.manage', 'advisor.rule.manage', 'advisor.rule.approve'] as $permission) {
                $checks['Permission catalog '.$permission] = DB::table('permissions')->where('name', $permission)->where('guard_name', 'api')->exists();
            }
            $checks['Platform privileges removed from tenant owners'] = ! DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->join('roles', 'roles.id', '=', 'permission_role.role_id')->where('roles.name', 'Tenant Owner')->where('permissions.name', 'like', 'platform.%')->exists();
        } catch (Throwable) {
            $checks['Database reachable'] = false;
        }
        try {
            $key = 'teami.probe.'.bin2hex(random_bytes(6));
            Cache::put($key, 'ok', 10);
            $checks['Cache reachable'] = Cache::get($key) === 'ok';
            Cache::forget($key);
        } catch (Throwable) {
            $checks['Cache reachable'] = false;
        }
        if ($this->option('ai')) {
            $checks['AI configuration present'] = filled(config('teami_ai.api_key')) && str_starts_with((string) config('teami_ai.endpoint'), 'https://') && filled(config('teami_ai.chat_model')) && filled(config('teami_ai.embedding_model'));
        }
        if ($this->option('runtime')) {
            foreach (['scheduler', 'worker'] as $type) {
                try {
                    $stamp = Cache::get('teami.'.$type.'_heartbeat');
                    $checks[ucfirst($type).' heartbeat'] = is_numeric($stamp) && now()->timestamp - $stamp < 180;
                } catch (Throwable) {
                    $checks[ucfirst($type).' heartbeat'] = false;
                }
            }
        }
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? 'PASS' : 'FAIL').' '.$name);
        }
        $this->line('Provider delivery, MySQL concurrency, backup restore and staging acceptance need separate verification.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
