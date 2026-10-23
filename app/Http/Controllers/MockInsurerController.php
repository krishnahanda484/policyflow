<?php

namespace App\Http\Controllers;

use App\Insurer\InsurerGateway;
use App\Insurer\MockInsurer;
use App\Services\ProviderCallbackProcessor;
use Illuminate\Http\JsonResponse;

/** Development helpers around the fake insurer. Disabled when MOCK_INSURER_CONTROLS=false. */
class MockInsurerController extends Controller
{
    /** GET /api/mock-insurer/policies/{key} : provider status lookup (key is PF-<request id>). */
    public function lookup(string $key, InsurerGateway $insurer): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(['data' => $insurer->lookup($key)->toArray()]);
    }

    /** POST /api/mock-insurer/deliver-callbacks : makes the insurer send its queued (delayed/duplicate) callbacks now. */
    public function deliverCallbacks(InsurerGateway $insurer, ProviderCallbackProcessor $receiver): JsonResponse
    {
        $this->ensureEnabled();
        abort_unless($insurer instanceof MockInsurer, 404);

        return response()->json(['data' => ['deliveries' => $insurer->deliverCallbacks($receiver)]]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('insurer.mock.controls_enabled'), 404, 'Mock insurer controls are disabled.');
    }
}
