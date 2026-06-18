<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Contracts\ToolAuthorizer;

/**
 * Authorizes tool use through Laravel's authorization Gate (Task E7). The host defines the policy:
 *
 *     Gate::define('ai-guardrails:use-tool', fn ($user, string $toolClass) => $user->mayUse($toolClass));
 *
 * The configured ability is checked against the current user with the tool class as the argument.
 * Fails CLOSED: if the ability is undefined, the user is unauthenticated, or the gate throws, the
 * tool is DENIED (a security gate must never default to allow). Every denial is logged.
 */
final readonly class GateToolAuthorizer implements ToolAuthorizer
{
    public function __construct(
        private Gate $gate,
        private string $ability,
    ) {}

    public function authorize(string $toolIdentifier): bool
    {
        try {
            // Gate::allows returns false for an undefined ability or an unauthenticated user, which is
            // exactly the fail-closed posture we want. Any thrown policy is also treated as a denial.
            $allowed = $this->gate->allows($this->ability, $toolIdentifier);
        } catch (\Throwable $e) {
            Log::warning('laravel-ai-guardrails: tool authorization gate threw; denying.', [
                'ability' => $this->ability,
                'tool' => $toolIdentifier,
                'exception' => $e,
            ]);

            return false;
        }

        if (! $allowed) {
            Log::info('laravel-ai-guardrails: tool use denied by authorization gate.', [
                'ability' => $this->ability,
                'tool' => $toolIdentifier,
            ]);
        }

        return $allowed;
    }
}
