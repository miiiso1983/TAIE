<?php

namespace App\Modules\Advisor\Services;

use Carbon\CarbonImmutable;

class WeeklyForecastModelService
{
    /** All candidates use identical origins; the final four observations never select a model. */
    public function evaluate(array $series): array
    {
        $y = array_map(fn ($w) => $w['sold_milli'] / 1000, $series);
        $n = count($y);
        $seasonal = $n >= 60;
        $start = $seasonal ? 52 : 6;
        $holdoutStart = $n - 4;
        $models = ['last_week', 'mean_4_weeks'];
        if ($seasonal) {
            $models[] = 'seasonal_52_weeks';
        }
        $errors = array_fill_keys($models, []);
        $origins = [];
        $predict = function (int $i) use ($y, $seasonal): array {
            $pred = ['last_week' => $y[$i - 1], 'mean_4_weeks' => array_sum(array_slice($y, $i - 4, 4)) / 4];
            if ($seasonal) {
                $pred['seasonal_52_weeks'] = $y[$i - 52];
            }

            return $pred;
        };
        for ($i = $start; $i < $n; $i++) {
            $pred = $predict($i);
            foreach ($pred as $model => $value) {
                $errors[$model][] = abs($y[$i] - $value);
            }
            $origins[] = ['phase' => $i < $holdoutStart ? 'selection' : 'holdout', 'training_to' => $series[$i - 1]['to'], 'test_from' => $series[$i]['from'], 'actual' => $y[$i], ...$pred];
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
        $estimate = $predict($n)[$winner];
        $residuals = $errors[$winner];
        sort($residuals);
        $radius = $residuals[(int) ceil(count($residuals) * 0.9) - 1];
        $to = CarbonImmutable::parse($series[$n - 1]['to']);

        return [
            'backtest' => ['method' => 'expanding_origin_one_week', 'model_selection_uses_this_backtest' => false, 'selection_uses_earlier_origins_only' => true, 'independent_holdout' => true, 'holdout_weeks' => 4, 'holdout_from' => $series[$holdoutStart]['from'], 'mae' => $mae, 'holdout_mae' => $holdoutMae, 'selection_origins' => $selectionCount, 'holdout_selected_model_mae' => $holdoutMae[$winner], 'origins' => $origins, 'seasonal_candidate_enabled' => $seasonal, 'seasonal_lag_weeks' => $seasonal ? 52 : null, 'common_training_minimum_weeks' => $start],
            'forecast' => ['model' => $winner, 'from' => $to->addDay()->toDateString(), 'to' => $to->addWeek()->toDateString(), 'quantity' => round($estimate, 3), 'lower' => round(max(0, $estimate - $radius), 3), 'upper' => round($estimate + $radius, 3), 'interval' => 'empirical_absolute_error_90th_percentile', 'calibrated' => false, 'target' => 'gross_sold_quantity'],
        ];
    }
}
