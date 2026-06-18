<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Events\DestructiveToolRouted;
use Padosoft\AiGuardrails\Support\ControlMode;
use Stringable;

/**
 * Decorator for a DESTRUCTIVE tool (refund/delete/send_email...). Instead of letting the model pull
 * the trigger, handle() parks the call for human approval and returns a "pending approval" string.
 * The real execution happens later, when a human approves (the flow runs the tool then). When
 * approval is unavailable (flow absent), the configured fallback applies: 'deny' refuses, 'pass'
 * executes the delegate.
 *
 * Modes: `enforce` parks the call (the behaviour above); `monitor` executes the delegate directly
 * (shadow rollout — the destructive call runs unblocked) and emits a structured log entry so the
 * operator can observe which calls would have been gated. E4 events will replace the log entry with
 * a domain event. `off` never wraps the tool, so this decorator only sees enforce/monitor.
 *
 * Security notes:
 * - The plain-text approval token is NEVER returned to the model; only a non-secret run reference
 *   is included so the model can relay it to the user without leaking the approval credential.
 * - If routing fails (flow misconfigured), the safe deny path is taken rather than throwing.
 */
final readonly class ApprovalGatedTool implements Tool
{
    /**
     * @param  Closure():(int|string|null)  $principalResolver
     * @param  'deny'|'pass'  $fallback
     */
    public function __construct(
        private Tool $delegate,
        private ApprovalRouter $router,
        private Closure $principalResolver,
        private string $toolName,
        private string $fallback = 'deny',
        private ControlMode $mode = ControlMode::Enforce,
        private ?Dispatcher $events = null,
    ) {
        if (! in_array($this->fallback, ['deny', 'pass'], true)) {
            throw new InvalidArgumentException(
                "ApprovalGatedTool fallback must be 'deny' or 'pass', got '{$this->fallback}'."
            );
        }
    }

    public function description(): Stringable|string
    {
        return $this->delegate->description();
    }

    public function handle(Request $request): Stringable|string
    {
        // Monitor (shadow rollout): do not gate — run the destructive call directly. A structured
        // log entry is emitted so operators can see which calls would have been parked (until E4
        // events replace this with a domain event). Request args are intentionally omitted to avoid
        // logging PII/secrets; the tool name + principal provide sufficient signal for alerting.
        if (! $this->mode->enforces()) {
            Log::info('laravel-ai-guardrails: HITL monitor — would-have-gated destructive call ran unblocked.', [
                'tool' => $this->toolName,
                'delegate' => $this->delegate::class,
                'principal' => ($this->principalResolver)(),
            ]);

            return $this->delegate->handle($request);
        }

        if (! $this->router->isAvailable()) {
            return $this->fallback === 'pass'
                ? $this->delegate->handle($request)
                : "This destructive action [{$this->toolName}] is blocked: human approval is required but unavailable.";
        }

        $principal = ($this->principalResolver)();

        try {
            $pending = $this->router->route(
                $this->toolName,
                $this->delegate::class,
                $request->toArray(),
                $principal,
            );
        } catch (\Throwable) {
            // ANY router failure (flow misconfiguration, token issuance, persistence, etc.) — fail
            // safe (deny). A destructive action must never proceed when approval routing breaks.
            return "This destructive action [{$this->toolName}] is blocked: the approval system could not park the request.";
        }

        // Emit from the same path that parked the call. Carries the non-secret run reference only
        // (never the approval token) so the host can notify an approver / wire SIEM.
        $this->events?->dispatch(new DestructiveToolRouted(
            $this->toolName,
            $principal,
            $pending->runId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ));

        // Return only the non-secret run reference. The plain-text approval token is intentionally
        // withheld from the model response to prevent token leakage via conversation logs or relay.
        return "This destructive action [{$this->toolName}] requires human approval. "
            ."Reference: {$pending->runId} (expires {$pending->expiresAt->format(DATE_ATOM)}).";
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate->schema($schema);
    }
}
