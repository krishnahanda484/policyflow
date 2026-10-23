<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PolicyRequest extends Model
{
    /** Columns that make up the accepted quote. Once the row exists they can never change. */
    public const IMMUTABLE = [
        'client_request_id', 'agent_id', 'date_of_birth', 'destination', 'trip_start', 'trip_end',
        'plan_code', 'sum_insured', 'travel_days', 'age_on_trip_start', 'base_daily_rate',
        'age_multiplier', 'destination_multiplier', 'coverage_multiplier', 'premium', 'currency',
    ];

    // Only our own services create/update rows (never mass-assigned from request input).
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'trip_start' => 'date:Y-m-d',
            'trip_end' => 'date:Y-m-d',
            'sum_insured' => 'integer',
            'travel_days' => 'integer',
            'age_on_trip_start' => 'integer',
            'base_daily_rate' => 'decimal:2',
            'age_multiplier' => 'decimal:2',
            'destination_multiplier' => 'decimal:2',
            'coverage_multiplier' => 'decimal:2',
            'premium' => 'decimal:2',
            'status' => PolicyStatus::class,
            'attempt_count' => 'integer',
            'last_response' => 'array',
            'failure_retryable' => 'boolean',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The premium is a snapshot of what the customer was quoted: refuse any later change.
        static::updating(function (self $model) {
            foreach (self::IMMUTABLE as $column) {
                if ($model->isDirty($column)) {
                    throw new LogicException("PolicyRequest.{$column} is immutable once created.");
                }
            }
        });
    }

    /** The idempotency key we send to the insurer on every attempt. Stable per request. */
    public function providerIdempotencyKey(): string
    {
        return 'PF-'.$this->getKey();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('attempt_number');
    }

    public function history(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }
}
