<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
