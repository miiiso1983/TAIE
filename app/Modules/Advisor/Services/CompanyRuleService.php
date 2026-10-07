<?php

namespace App\Modules\Advisor\Services;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class CompanyRuleService
{
    public static function allowed(User $u, string $kind): bool
    {
        $rights = match ($kind) {
            'credit' => ['advisor.sales.read', 'sales.customer.read'],'leave' => ['hr.employee.read', 'hr.leave.read'],'deduction' => ['hr.compensation.read'],default => []
        };
        if ($rights === []) {
            return false;
        }foreach ($rights as $p) {
            if (! $u->hasTenantPermission(app(TenantContext::class)->getTenantId(), $p)) {
                return false;
            }
        }

return true;
    }

    public function rules(string $kind, array $rules): array
    {
        $integer = ['required', 'integer', 'min:0', 'max:1000000000000'];
        $decimal = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/D'];
        $v = match ($kind) {
            'credit' => ['currency' => ['required', 'in:USD,IQD'], 'limit_minor' => $integer, 'maximum_overdue_days' => ['required', 'integer', 'min:0', 'max:3650']],'leave' => ['maximum_days' => $decimal, 'require_balance' => ['required', 'boolean']],default => ['currency' => ['required', 'in:USD,IQD'], 'maximum_percent' => $decimal]
        };
        $d = Validator::make(['rules' => $rules], ['rules' => ['array:'.implode(',', array_keys($v))], ...array_combine(array_map(fn ($k) => 'rules.'.$k, array_keys($v)), array_values($v))])->validate()['rules'];
        if ($kind === 'leave') {
            abort_unless(bccomp($d['maximum_days'], '0', 2) > 0 && bccomp($d['maximum_days'], '365', 2) <= 0, 422, 'حد الإجازة يجب أن يكون موجباً ولا يتجاوز 365 يوماً.');
        }
        if ($kind === 'deduction') {
            abort_if(bccomp($d['maximum_percent'], '100', 2) > 0, 422, 'النسبة يجب ألا تتجاوز 100.');
        }

return $d;
    }

    public function evaluate(string $kind, array $rules, array $input): array
    {
        $nonnegative = ['required', 'integer', 'min:0', 'max:1000000000000'];
        $days = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/D'];
        $fields = match ($kind) {
            'credit' => ['currency' => ['required', 'in:USD,IQD'], 'amount_minor' => $nonnegative, 'outstanding_minor' => $nonnegative, 'overdue_days' => ['required', 'integer', 'min:0', 'max:36500']],'leave' => ['requested_days' => $days, 'balance_days' => $days],default => ['currency' => ['required', 'in:USD,IQD'], 'amount_minor' => $nonnegative, 'base_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'other_deductions_minor' => $nonnegative]
        };
        $d = Validator::make(['input' => $input], ['input' => ['array:'.implode(',', array_keys($fields))], ...array_combine(array_map(fn ($k) => 'input.'.$k, array_keys($fields)), array_values($fields))])->validate()['input'];
        $checks = [];
        if ($kind === 'credit') {
            $exposure = bcadd((string) $d['amount_minor'], (string) $d['outstanding_minor'], 0);
            $checks = [['code' => 'currency', 'met' => $d['currency'] === $rules['currency']], ['code' => 'credit_limit', 'met' => bccomp($exposure, (string) $rules['limit_minor'], 0) <= 0, 'actual' => $exposure, 'limit' => (string) $rules['limit_minor']], ['code' => 'overdue_days', 'met' => $d['overdue_days'] <= $rules['maximum_overdue_days'], 'actual' => $d['overdue_days'], 'limit' => $rules['maximum_overdue_days']]];
        } elseif ($kind === 'leave') {
            $checks = [['code' => 'positive_days', 'met' => bccomp($d['requested_days'], '0', 2) > 0], ['code' => 'maximum_days', 'met' => bccomp($d['requested_days'], $rules['maximum_days'], 2) <= 0, 'actual' => $d['requested_days'], 'limit' => $rules['maximum_days']], ['code' => 'balance', 'met' => ! $rules['require_balance'] || bccomp($d['requested_days'], $d['balance_days'], 2) <= 0, 'actual' => $d['balance_days']]];
        } else {
            $total = bcadd((string) $d['amount_minor'], (string) $d['other_deductions_minor'], 0);
            $cap = bcdiv(bcmul((string) $d['base_minor'], $rules['maximum_percent'], 2), '100', 0);
            $checks = [['code' => 'currency', 'met' => $d['currency'] === $rules['currency']], ['code' => 'deduction_cap', 'met' => bccomp($total, $cap, 0) <= 0, 'actual' => $total, 'limit' => $cap]];
        }

        return ['engine_version' => 'company_rules_v1', 'kind' => $kind, 'input' => $d, 'rules' => $rules, 'passed' => collect($checks)->every('met'), 'checks' => $checks, 'notice' => 'محاكاة حسابية لمدخلات أدخلها المراجع؛ ليست اعتماد دفع أو راتب أو رصيد إجازة أو إثبات مطابقة القانون.'];
    }
}
