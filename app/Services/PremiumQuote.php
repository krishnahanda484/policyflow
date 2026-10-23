<?php

namespace App\Services;

/** Immutable result of a premium calculation. All numbers are decimal strings (never floats). */
final class PremiumQuote
{
    public function __construct(
        public readonly int $travelDays,
        public readonly int $ageOnTripStart,
        public readonly string $baseDailyRate,
        public readonly string $ageMultiplier,
        public readonly string $destinationMultiplier,
        public readonly string $coverageMultiplier,
        public readonly string $premium,
    ) {}

    /** Column => value map stored on policy_requests as the immutable snapshot. */
    public function toSnapshot(): array
    {
        return [
            'travel_days' => $this->travelDays,
            'age_on_trip_start' => $this->ageOnTripStart,
            'base_daily_rate' => $this->baseDailyRate,
            'age_multiplier' => $this->ageMultiplier,
            'destination_multiplier' => $this->destinationMultiplier,
            'coverage_multiplier' => $this->coverageMultiplier,
            'premium' => $this->premium,
            'currency' => 'INR',
        ];
    }
}
