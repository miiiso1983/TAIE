<?php

namespace Tests\Unit;

use App\Modules\Advisor\Services\WeeklyForecastModelService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WeeklyForecastModelTest extends TestCase
{
    private function series(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $from = CarbonImmutable::parse('2024-01-01')->addWeeks($i);
            $rows[] = ['from' => $from->toDateString(), 'to' => $from->addDays(6)->toDateString(), 'sold_milli' => (($i % 52) % 2 === 0 ? 10 : 100) * 1000];
        }

        return $rows;
    }

    public function test_seasonal_repetition_wins_on_common_origins_without_using_holdout_to_select(): void
    {
        $rows = $this->series(64);
        $service = new WeeklyForecastModelService;
        $a = $service->evaluate($rows);
        $this->assertSame('seasonal_52_weeks', $a['forecast']['model']);
        $this->assertSame(10.0, $a['forecast']['quantity']);
        $this->assertEquals(0, $a['backtest']['mae']['seasonal_52_weeks']);
        $this->assertSame(8, $a['backtest']['selection_origins']);
        foreach (range(60, 63) as $i) {
            $rows[$i]['sold_milli'] = 500000;
        }
        $b = $service->evaluate($rows);
        $this->assertSame($a['backtest']['mae'], $b['backtest']['mae']);
        $this->assertSame($a['forecast']['model'], $b['forecast']['model']);
        $this->assertGreaterThan(0, $b['backtest']['holdout_selected_model_mae']);
        $this->assertSame($a['backtest']['origins'][0]['seasonal_52_weeks'], $b['backtest']['origins'][0]['seasonal_52_weeks']);
    }

    public function test_short_history_excludes_seasonal_candidate_and_minimum_long_history_has_four_selection_points(): void
    {
        $service = new WeeklyForecastModelService;
        $short = $service->evaluate($this->series(59));
        $this->assertFalse($short['backtest']['seasonal_candidate_enabled']);
        $this->assertArrayNotHasKey('seasonal_52_weeks', $short['backtest']['mae']);
        $long = $service->evaluate($this->series(60));
        $this->assertSame(4, $long['backtest']['selection_origins']);
        $this->assertCount(8, $long['backtest']['origins']);
        $this->assertSame('selection', $long['backtest']['origins'][3]['phase']);
        $this->assertSame('holdout', $long['backtest']['origins'][4]['phase']);
    }
}
