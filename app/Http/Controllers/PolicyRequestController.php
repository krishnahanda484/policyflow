<?php

namespace App\Http\Controllers;

use App\Enums\PolicyStatus;
use App\Http\Requests\ListPolicyRequestsRequest;
use App\Http\Requests\StorePolicyRequestRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use App\Services\PolicyIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PolicyRequestController extends Controller
{
    /** POST /api/policy-requests : 201 when created, 200 when the same (agent_id, client_request_id) already exists. */
    public function store(StorePolicyRequestRequest $request, PolicyIntakeService $intake): JsonResponse
    {
        [$policyRequest, $created] = $intake->handle($request->validated());

        return (new PolicyRequestResource($policyRequest))
            ->additional(['meta' => ['created' => $created]])
            ->response()
            ->setStatusCode($created ? 201 : 200)
            ->header('Idempotent-Replay', $created ? 'false' : 'true');
    }

    /** GET /api/policy-requests/{id} : state, premium, references, attempts and audit trail. */
    public function show(int $id): PolicyRequestResource
    {
        return new PolicyRequestResource(PolicyRequest::with(['attempts', 'history'])->findOrFail($id));
    }

    /** GET /api/policy-requests?status=&per_page=&page= */
    public function index(ListPolicyRequestsRequest $request): AnonymousResourceCollection
    {
        $query = PolicyRequest::query()->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return PolicyRequestResource::collection(
            $query->paginate($request->integer('per_page', 15))->withQueryString()
        );
    }

    /**
     * POST /api/policy-requests/{id}/submit : start or safely resume issuance.
     * 200 ISSUED | 202 still in progress (SUBMITTED / PENDING_CONFIRMATION / NEEDS_RECONCILIATION)
     * 502 FAILED (see failure_retryable) | 409 not allowed | 404 unknown id
     */
    public function submit(int $id, IssuanceService $issuance): JsonResponse
    {
        $policyRequest = $issuance->submit($id)->load(['attempts', 'history']);

        $http = match ($policyRequest->status) {
            PolicyStatus::ISSUED => 200,
            PolicyStatus::FAILED => 502,
            default => 202,
        };

        return (new PolicyRequestResource($policyRequest))->response()->setStatusCode($http);
    }
}
