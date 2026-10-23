<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'PolicyFlow',
    'docs' => 'See README.md. API lives under /api/policy-requests.',
]));
