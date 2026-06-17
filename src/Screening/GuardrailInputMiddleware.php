<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;

/**
 * laravel/ai agent middleware (Control B). Screens the prompt BEFORE the model is called; on a
 * block it refuses by returning a fabricated AgentResponse WITHOUT invoking $next (the model is
 * never reached). EVERY attempt — blocked and allowed — is appended to the append-only audit.
 */
final readonly class GuardrailInputMiddleware
{
    /** @param (Closure():(int|string|null))|null $principalResolver */
    public function __construct(
        private InjectionScreener $screener,
        private InjectionAuditStore $audit,
        private ?Closure $principalResolver = null,
        private bool $enabled = true,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        // Master / control disabled → true pass-through: no screening, no audit, no auth resolution.
        if (! $this->enabled) {
            return $next($prompt);
        }

        $verdict = $this->screener->screen($prompt->prompt);

        $principal = $this->principalResolver !== null ? ($this->principalResolver)() : null;

        $this->audit->append(new InjectionAttempt(
            $prompt->prompt,
            $verdict->blocked,
            $verdict->ruleId,
            $principal !== null ? (string) $principal : null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $verdict->rulesetVersion,
            $verdict->erroredRuleIds,
            $verdict->matchedSpan,
        ));

        if ($verdict->blocked) {
            // Refuse without calling the model: $next is never invoked.
            return new AgentResponse(
                $prompt->invocationId ?? '',
                $verdict->refusalMessage ?? 'This request was blocked by the input guardrails.',
                new Usage,
                new Meta,
            );
        }

        return $next($prompt);
    }
}
