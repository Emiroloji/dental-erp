<?php

namespace Tests\Unit\Forecasting;

use App\Domain\Forecasting\Support\HoltForecaster;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HoltForecasterTest extends TestCase
{
    public function test_linear_series_is_extended_exactly(): void
    {
        // Seviye 10→16, trend sabit +2: bir sonraki hafta 18.
        $result = HoltForecaster::forecast([10, 12, 14, 16], 0.5, 0.3);

        $this->assertEqualsWithDelta(16.0, $result['level'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $result['trend'], 1e-9);
        $this->assertEqualsWithDelta(18.0, $result['next'], 1e-9);
    }

    public function test_hand_computed_jump(): void
    {
        // L0=10 T0=0; t1: L=10 T=0; t2: L=0.5·20+0.5·10=15, T=0.3·5=1.5 → 16.5
        $result = HoltForecaster::forecast([10, 10, 20], 0.5, 0.3);

        $this->assertEqualsWithDelta(15.0, $result['level'], 1e-9);
        $this->assertEqualsWithDelta(1.5, $result['trend'], 1e-9);
        $this->assertEqualsWithDelta(16.5, $result['next'], 1e-9);
    }

    public function test_constant_series_forecasts_the_same_value(): void
    {
        $this->assertEqualsWithDelta(7.0, HoltForecaster::forecast([7, 7, 7, 7], 0.5, 0.3)['next'], 1e-9);
    }

    public function test_falling_series_never_forecasts_negative_consumption(): void
    {
        $result = HoltForecaster::forecast([20, 10, 0], 0.5, 0.3);

        $this->assertLessThan(0, $result['level'] + $result['trend']);
        $this->assertSame(0.0, $result['next']);
    }

    public function test_single_observation_has_no_trend(): void
    {
        $this->assertSame(['level' => 5.0, 'trend' => 0.0, 'next' => 5.0], HoltForecaster::forecast([5], 0.5, 0.3));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HoltForecaster::forecast([], 0.5, 0.3);
    }

    public function test_out_of_range_parameters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HoltForecaster::forecast([1, 2], 1.5, 0.3);
    }
}
