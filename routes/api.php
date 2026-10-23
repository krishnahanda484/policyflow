<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use App\Http\Controllers\ProviderCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show'])->whereNumber('id');
Route::post('/policy-requests/{id}/submit', [PolicyRequestController::class, 'submit'])->whereNumber('id');

// Receiver for provider events (the mock insurer posts here).
Route::post('/mock-insurer/callback', ProviderCallbackController::class);

// Fake-insurer development helpers.
Route::get('/mock-insurer/policies/{key}', [MockInsurerController::class, 'lookup']);
Route::post('/mock-insurer/deliver-callbacks', [MockInsurerController::class, 'deliverCallbacks']);
