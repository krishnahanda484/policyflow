<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail of every state change. */
class PolicyStateHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'policy_state_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => PolicyStatus::class,
            'to_status' => PolicyStatus::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
