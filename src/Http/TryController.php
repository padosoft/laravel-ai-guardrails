<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\AiGuardrails;
use Padosoft\AiGuardrails\Http\Support\Envelope;

/**
 * Sandbox endpoints (no persistence): screen a prompt / sanitize a text blob over the deterministic
 * controls, so an operator can build trust in them.
 */
final class TryController
{
    public function screen(Request $request, AiGuardrails $guardrails): JsonResponse
    {
        $verdict = $guardrails->screen((string) $request->input('prompt', ''));

        return Envelope::make(ApiSchema::SCHEMA_TRY_SCREEN, [
            'blocked' => $verdict->blocked,
            'rule_id' => $verdict->ruleId,
            'refusal_message' => $verdict->refusalMessage,
            'ruleset_version' => $verdict->rulesetVersion,
        ]);
    }

    public function sanitize(Request $request, AiGuardrails $guardrails): JsonResponse
    {
        return Envelope::make(ApiSchema::SCHEMA_TRY_SANITIZE, [
            'sanitized' => $guardrails->sanitize((string) $request->input('text', '')),
        ]);
    }
}
