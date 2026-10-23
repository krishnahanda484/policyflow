<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private function premium(string $plan, string $dob, string $start, string $end, string $dest, int $sum): string
    {
        return (new PremiumCalculator)->calculate($plan, $dob, $start, $end, $dest, $sum)->premium;
    }

    public function test_assignment_example_is_225(): void
    {
        $quote = (new PremiumCalculator)->calculate('PLUS', '1998-04-10', '2026-11-10', '2026-11-14', 'INTERNATIONAL', 300000);

        $this->assertSame('225.00', $quote->premium); // 20 x 5 x 1.00 x 1.80 x 1.25
        $this->assertSame(5, $quote->travelDays);
        $this->assertSame(28, $quote->ageOnTripStart);
        $this->assertSame('1.00', $quote->ageMultiplier);
        $this->assertSame('1.80', $quote->destinationMultiplier);
        $this->assertSame('1.25', $quote->coverageMultiplier);
    }

    public function test_one_day_trip_counts_as_one_day(): void
    {
        $this->assertSame('12.00', $this->premium('BASIC', '1990-01-01', '2026-11-10', '2026-11-10', 'DOMESTIC', 100000));
    }

    public function test_days_are_inclusive_across_month_end(): void
    {
        $quote = (new PremiumCalculator)->calculate('BASIC', '1990-01-01', '2026-11-29', '2026-12-02', 'DOMESTIC', 100000);
        $this->assertSame(4, $quote->travelDays);
        $this->assertSame('48.00', $quote->premium);
    }

    #[DataProvider('plans')]
    public function test_plan_base_rates(string $plan, string $expected): void
    {
        $this->assertSame($expected, $this->premium($plan, '1990-01-01', '2026-11-10', '2026-11-10', 'DOMESTIC', 100000));
    }

    public static function plans(): array
    {
        return [['BASIC', '12.00'], ['PLUS', '20.00'], ['PREMIUM', '30.00']];
    }

    #[DataProvider('ageBands')]
    public function test_age_bands(string $dob, string $expectedMultiplier, int $expectedAge): void
    {
        $quote = (new PremiumCalculator)->calculate('BASIC', $dob, '2026-11-10', '2026-11-10', 'DOMESTIC', 100000);
        $this->assertSame($expectedMultiplier, $quote->ageMultiplier);
        $this->assertSame($expectedAge, $quote->ageOnTripStart);
    }

    public static function ageBands(): array
    {
        return [
            'newborn' => ['2026-11-10', '1.10', 0],
            'turns 18 tomorrow = still 17' => ['2008-11-11', '1.10', 17],
            'turns 18 on trip start' => ['2008-11-10', '1.00', 18],
            'age 60' => ['1966-11-10', '1.00', 60],
            'turns 61 tomorrow = still 60' => ['1965-11-11', '1.00', 60],
            'turns 61 on trip start' => ['1965-11-10', '1.50', 61],
            'age 70' => ['1956-11-10', '1.50', 70],
            'turns 71 tomorrow = still 70' => ['1955-11-11', '1.50', 70],
            'turns 71 on trip start' => ['1955-11-10', '2.00', 71],
            'age 95' => ['1931-01-01', '2.00', 95],
        ];
    }

    public function test_destination_and_coverage_multipliers(): void
    {
        // 12 x 1 x 1.00 x 1.80 x 1.50
        $this->assertSame('32.40', $this->premium('BASIC', '1990-01-01', '2026-11-10', '2026-11-10', 'INTERNATIONAL', 500000));
        // 12 x 1 x 1.00 x 1.00 x 1.25
        $this->assertSame('15.00', $this->premium('BASIC', '1990-01-01', '2026-11-10', '2026-11-10', 'DOMESTIC', 300000));
    }

    public function test_decimal_safe_for_values_that_floats_get_wrong(): void
    {
        // 12 x 1.10 x 1.80 x 1.25 = 29.70 exactly; naive float maths can give 29.700000000000003.
        $this->assertSame('29.70', $this->premium('BASIC', '2026-01-01', '2026-11-10', '2026-11-10', 'INTERNATIONAL', 300000));
    }

    public function test_trip_end_before_start_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->premium('BASIC', '1990-01-01', '2026-11-10', '2026-11-09', 'DOMESTIC', 100000);
    }

    public function test_unsupported_plan_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->premium('GOLD', '1990-01-01', '2026-11-10', '2026-11-10', 'DOMESTIC', 100000);
    }

    public function test_unsupported_coverage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->premium('BASIC', '1990-01-01', '2026-11-10', '2026-11-10', 'DOMESTIC', 200000);
    }

    public function test_unsupported_destination_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->premium('BASIC', '1990-01-01', '2026-11-10', '2026-11-10', 'MOON', 100000);
    }
}
