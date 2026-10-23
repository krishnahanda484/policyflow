<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * premium = base_daily_rate x travel_days x age x destination x coverage, rounded half-up to 2 dp.
 *
 * Money maths uses bcmath on decimal strings: no binary floating point anywhere.
 * Every multiplier has at most 2 decimals, so the intermediate product is exact at scale 6.
 */
class PremiumCalculator
{
    public const PLANS = ['BASIC' => '12', 'PLUS' => '20', 'PREMIUM' => '30'];

    public const DESTINATIONS = ['DOMESTIC' => '1.00', 'INTERNATIONAL' => '1.80'];

    public const COVERAGES = [100000 => '1.00', 300000 => '1.25', 500000 => '1.50'];

    public function calculate(
        string $planCode,
        CarbonInterface|string $dateOfBirth,
        CarbonInterface|string $tripStart,
        CarbonInterface|string $tripEnd,
        string $destination,
        int $sumInsured,
    ): PremiumQuote {
        if (! isset(self::PLANS[$planCode])) {
            throw new InvalidArgumentException("Unsupported plan_code: {$planCode}");
        }
        if (! isset(self::DESTINATIONS[$destination])) {
            throw new InvalidArgumentException("Unsupported destination: {$destination}");
        }
        if (! isset(self::COVERAGES[$sumInsured])) {
            throw new InvalidArgumentException("Unsupported sum_insured: {$sumInsured}");
        }

        $dob = CarbonImmutable::parse($dateOfBirth)->startOfDay();
        $start = CarbonImmutable::parse($tripStart)->startOfDay();
        $end = CarbonImmutable::parse($tripEnd)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('trip_end must be on or after trip_start.');
        }
        if ($dob->gt($start)) {
            throw new InvalidArgumentException('date_of_birth must not be after trip_start.');
        }

        $days = max(1, (int) $start->diffInDays($end) + 1); // inclusive
        $age = (int) floor($dob->diffInYears($start));      // completed years on trip start

        $base = self::PLANS[$planCode];
        $ageMultiplier = $this->ageMultiplier($age);
        $destinationMultiplier = self::DESTINATIONS[$destination];
        $coverageMultiplier = self::COVERAGES[$sumInsured];

        $raw = bcmul($base, (string) $days, 6);
        $raw = bcmul($raw, $ageMultiplier, 6);
        $raw = bcmul($raw, $destinationMultiplier, 6);
        $raw = bcmul($raw, $coverageMultiplier, 6);

        // bcadd truncates, so adding 0.005 first gives round-half-up at 2 dp (values are never negative).
        $premium = bcadd($raw, '0.005', 2);

        return new PremiumQuote(
            travelDays: $days,
            ageOnTripStart: $age,
            baseDailyRate: bcadd($base, '0', 2),
            ageMultiplier: $ageMultiplier,
            destinationMultiplier: $destinationMultiplier,
            coverageMultiplier: $coverageMultiplier,
            premium: $premium,
        );
    }

    public function ageMultiplier(int $age): string
    {
        return match (true) {
            $age <= 17 => '1.10',
            $age <= 60 => '1.00',
            $age <= 70 => '1.50',
            default => '2.00',
        };
    }
}
