<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderCallbackRequest;
use App\Services\ProviderCallbackProcessor;
use Illuminate\Http\JsonResponse;

class ProviderCallbackController extends Controller
{
    /**
     * POST /api/mock-insurer/callback
     * Always 200 for a well-formed callback about a known request, including redeliveries,
     * so the insurer stops retrying. `result` says what we did: APPLIED | DUPLICATE | IGNORED.
     */
    public function __invoke(ProviderCallbackRequest $request, ProviderCallbackProcessor $processor): JsonResponse
    {
        $outcome = $processor->process($request->validated());

        return response()->json(['data' => [
            'event_id' => $request->validated('event_id'),
            'result' => $outcome['result'],
            'policy_request_id' => $outcome['request']->id,
            'status' => $outcome['request']->status->value,
            'policy_number' => $outcome['request']->policy_number,
        ]]);
    }
}
