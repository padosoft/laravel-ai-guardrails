<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Events\InjectionBlocked;
use Padosoft\AiGuardrails\Events\InjectionObserved;
use Padosoft\AiGuardrails\Support\ControlMode;

/**
 * laravel/ai agent middleware (Control B). Screens the prompt BEFORE the model is called; in
 * `enforce` mode a block refuses by returning a fabricated AgentResponse WITHOUT invoking $next (the
 * model is never reached). In `monitor` mode the detection is audited (`blocked=false`, rule id set)
 * but the prompt is passed through. EVERY attempt — blocked, observed, and allowed — is appended to
 * the append-only audit. `off` → pure pass-through.
 */
final readonly class GuardrailInputMiddleware
{
    /** @param (Closure():(int|string|null))|null $principalResolver */
    public function __construct(
        private InjectionScreener $screener,
        private InjectionAuditStore $audit,
        private ?Closure $principalResolver = null,
        private bool $enabled = true,
        private ?ControlMode $mode = null,
        private ?Dispatcher $events = null,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        // Explicit mode wins; otherwise the legacy `enabled` flag maps true→enforce, false→off.
        $mode = $this->mode ?? ($this->enabled ? ControlMode::Enforce : ControlMode::Off);

        // Control off → true pass-through: no screening, no audit, no auth resolution.
        if (! $mode->isActive()) {
            return $next($prompt);
        }

        $verdict = $this->screener->screen($prompt->prompt);

        $principal = $this->principalResolver !== null ? ($this->principalResolver)() : null;

        // In monitor mode the prompt is NOT blocked, so the audit records blocked=false but keeps the
        // matched rule id — distinguishing an observed injection from a clean allow.
        $willBlock = $verdict->blocked && $mode->enforces();

        $attempt = new InjectionAttempt(
            $prompt->prompt,
            $willBlock,
            $verdict->ruleId,
            $principal !== null ? (string) $principal : null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $verdict->rulesetVersion,
            $verdict->erroredRuleIds,
            $verdict->matchedSpan,
        );

        $this->audit->append($attempt);

        // Emit a domain event from the SAME path that wrote the audit row (only on a detection — a
        // clean allow produces no event). enforce → Blocked; monitor → Observed (would-have-blocked).
        if ($verdict->blocked) {
            $this->events?->dispatch($willBlock ? new InjectionBlocked($attempt) : new InjectionObserved($attempt));
        }

        if ($willBlock) {
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
