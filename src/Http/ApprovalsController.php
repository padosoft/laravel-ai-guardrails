<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Hitl\ApprovalReadModel;
use Padosoft\AiGuardrails\Http\Support\Envelope;

/**
 * HITL approval surface (Control D): list pending approvals (read model) and approve/reject a parked
 * destructive tool call by token. The decision actor's principal is derived server-side so a client
 * cannot spoof who approved. When HITL is unavailable, decisions return 409 rather than 500.
 */
final class ApprovalsController
{
    public function index(ApprovalReadModel $readModel): JsonResponse
    {
        return Envelope::make(ApiSchema::SCHEMA_APPROVAL_LIST, [
            'pending' => $readModel->pending(),
        ]);
    }

    public function approve(Request $request, ApprovalRouter $router, string $token): JsonResponse
    {
        return $this->decide($request, $router, $token, approve: true);
    }

    public function reject(Request $request, ApprovalRouter $router, string $token): JsonResponse
    {
        return $this->decide($request, $router, $token, approve: false);
    }

    private function decide(Request $request, ApprovalRouter $router, string $token, bool $approve): JsonResponse
    {
        if (! $router->isAvailable()) {
            return Envelope::make(ApiSchema::SCHEMA_APPROVAL_DECISION, [
                'decision' => 'unavailable',
                'error' => 'hitl_unavailable',
            ], 409);
        }

        $actor = $this->actor($request);

        try {
            $approve ? $router->approve($token, $actor) : $router->reject($token, $actor);
        } catch (\LogicException $e) {
            // LogicException means the router reports itself unavailable at call-time (race or
            // misconfiguration). Map to 409, same as the isAvailable() fast-path above.
            Log::warning('laravel-ai-guardrails: approval router unavailable at decision time.', [
                'decision' => $approve ? 'approve' : 'reject',
                'exception' => $e->getMessage(),
            ]);

            return Envelope::make(ApiSchema::SCHEMA_APPROVAL_DECISION, [
                'decision' => 'unavailable',
                'error' => 'hitl_unavailable',
            ], 409);
        } catch (\Throwable $e) {
            // Invalid / expired / already-consumed token, or a transient flow error. Fail with a
            // clean 422 (not a 500) and log for diagnosis.
            Log::warning('laravel-ai-guardrails: approval decision failed.', [
                'decision' => $approve ? 'approve' : 'reject',
                'exception' => $e->getMessage(),
            ]);

            return Envelope::make(ApiSchema::SCHEMA_APPROVAL_DECISION, [
                'decision' => 'failed',
                'error' => 'decision_failed',
            ], 422);
        }

        return Envelope::make(ApiSchema::SCHEMA_APPROVAL_DECISION, [
            'decision' => $approve ? 'approved' : 'rejected',
        ]);
    }

    /**
     * Build the decision actor. The authenticated principal is resolved server-side and is
     * authoritative (placed last so a client-supplied "actor" cannot override it). Client metadata is
     * accepted only as flat string-keyed scalars.
     *
     * @return array<string,mixed>
     */
    private function actor(Request $request): array
    {
        $raw = $request->input('actor');
        $client = is_array($raw)
            ? array_filter($raw, static fn ($v, $k): bool => is_string($k) && is_scalar($v), ARRAY_FILTER_USE_BOTH)
            : [];

        return array_merge($client, [
            'principal_id' => rescue(static fn () => auth()->guard()->id(), null, false),
            'via' => 'ai-guardrails.api',
        ]);
    }
}
