<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A callback the fake insurer still has to deliver (possibly several times). */
class MockInsurerEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
