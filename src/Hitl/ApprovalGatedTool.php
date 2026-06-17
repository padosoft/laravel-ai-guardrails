<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
 */
final readonly class ApprovalGatedTool implements Tool
{
    /** @param Closure():(int|string|null) $principalResolver */
    public function __construct(
        private Tool $delegate,
        private ApprovalRouter $router,
        private Closure $principalResolver,
        private string $toolName,
        private string $fallback = 'deny',
    ) {}

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

        $pending = $this->router->route(
            $this->toolName,
            $this->delegate::class,
            $request->toArray(),
            ($this->principalResolver)(),
        );

        return "This destructive action [{$this->toolName}] requires human approval. "
            ."Token: {$pending->token} (expires {$pending->expiresAt->format(DATE_ATOM)}).";
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate->schema($schema);
    }
}
