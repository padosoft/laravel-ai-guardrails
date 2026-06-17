<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Stringable;

/**
 * Decorator for a DESTRUCTIVE tool (refund/delete/send_email...). Instead of letting the model pull
 * the trigger, handle() parks the call for human approval and returns a "pending approval" string.
 * The real execution happens later, when a human approves (the flow runs the tool then). When
 * approval is unavailable (flow absent), the configured fallback applies: 'deny' refuses, 'pass'
 * executes the delegate.
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
        if (! $this->router->isAvailable()) {
            return $this->fallback === 'pass'
                ? $this->delegate->handle($request)
                : "This destructive action [{$this->toolName}] is blocked: human approval is required but unavailable.";
        }

        try {
            $pending = $this->router->route(
                $this->toolName,
                $this->delegate::class,
                $request->toArray(),
                ($this->principalResolver)(),
            );
        } catch (\Throwable) {
            // ANY router failure (flow misconfiguration, token issuance, persistence, etc.) — fail
            // safe (deny). A destructive action must never proceed when approval routing breaks.
            return "This destructive action [{$this->toolName}] is blocked: the approval system could not park the request.";
        }

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
