<?php

namespace App\Modules\Advisor\Services;

use Carbon\CarbonImmutable;

class WeeklyForecastModelService
{
    /** All candidates use identical origins; the final four observations never select a model. */
    public function evaluate(array $series, int $horizon = 1): array
    {
        if (! in_array($horizon, [1, 2], true)) {
            throw new \InvalidArgumentException('Supported forecast horizons are one or two weeks.');
        }
        $y = array_map(fn ($w) => $w['sold_milli'] / 1000, $series);
        $n = count($y);
        $seasonal = $n >= 59 + $horizon;
        $trainingMinimum = $seasonal ? 52 : 6;
        $start = $trainingMinimum + $horizon - 1;
        $holdoutStart = $n - 4;
        $models = ['last_week', 'mean_4_weeks'];
        if ($seasonal) {
            $models[] = 'seasonal_52_weeks';
        }
        $errors = array_fill_keys($models, []);
        $origins = [];
        $predict = function (int $target) use ($y, $seasonal, $horizon): array {
            $trainingCount = $target - $horizon + 1;
            $window = array_slice($y, $trainingCount - 4, 4);
            $mean = 0;
            for ($step = 0; $step < $horizon; $step++) {
                $mean = array_sum($window) / 4;
                array_shift($window);
                $window[] = $mean;
            }
            $pred = ['last_week' => $y[$trainingCount - 1], 'mean_4_weeks' => $mean];
            if ($seasonal) {
                $pred['seasonal_52_weeks'] = $y[$target - 52];
            }

            return $pred;
        };
        for ($i = $start; $i < $n; $i++) {
            $pred = $predict($i);
            foreach ($pred as $model => $value) {
                $errors[$model][] = abs($y[$i] - $value);
            }
            $origins[] = ['phase' => $i < $holdoutStart ? 'selection' : 'holdout', 'training_to' => $series[$i - $horizon]['to'], 'test_from' => $series[$i]['from'], 'actual' => $y[$i], ...$pred];
        }
        $selectionCount = $holdoutStart - $start;
        $mae = array_map(fn ($x) => array_sum(array_slice($x, 0, $selectionCount)) / $selectionCount, $errors);
        $holdoutMae = array_map(fn ($x) => array_sum(array_slice($x, $selectionCount)) / 4, $errors);
        $winner = $models[0];
        foreach ($models as $model) {
            if ($mae[$model] < $mae[$winner]) {
                $winner = $model;
            }
        }
        $estimate = $predict($n + $horizon - 1)[$winner];
        $residuals = $errors[$winner];
        sort($residuals);
        $radius = $residuals[(int) ceil(count($residuals) * 0.9) - 1];
        $to = CarbonImmutable::parse($series[$n - 1]['to']);

        return [
            'backtest' => ['method' => 'expanding_origin_fixed_horizon', 'model_selection_uses_this_backtest' => false, 'selection_uses_earlier_origins_only' => true, 'independent_holdout' => true, 'holdout_weeks' => 4, 'holdout_from' => $series[$holdoutStart]['from'], 'mae' => $mae, 'holdout_mae' => $holdoutMae, 'selection_origins' => $selectionCount, 'holdout_selected_model_mae' => $holdoutMae[$winner], 'origins' => $origins, 'seasonal_candidate_enabled' => $seasonal, 'seasonal_lag_weeks' => $seasonal ? 52 : null, 'common_training_minimum_weeks' => $trainingMinimum, 'horizon_weeks' => $horizon],
            'forecast' => ['model' => $winner, 'horizon_weeks' => $horizon, 'from' => $to->addDay()->addWeeks($horizon - 1)->toDateString(), 'to' => $to->addWeeks($horizon)->toDateString(), 'quantity' => round($estimate, 3), 'lower' => round(max(0, $estimate - $radius), 3), 'upper' => round($estimate + $radius, 3), 'interval' => 'empirical_absolute_error_90th_percentile', 'calibrated' => false, 'target' => 'gross_sold_quantity'],
        ];
    }
}
