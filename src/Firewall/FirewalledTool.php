<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Exceptions\ToolArgumentRejection;
use Stringable;

/**
 * Decorator over a laravel/ai Tool that enforces the firewall before delegating:
 * (1) re-scope owner keys to the authenticated principal, (2) validate the scoped
 * arguments against the tool's own schema, (3) reject (throw) on any violation,
 * otherwise (4) delegate with the scoped arguments. Untrusted-input posture.
 */
final readonly class FirewalledTool implements Tool
{
    /** @param Closure():(int|string|null) $principalResolver */
    public function __construct(
        private Tool $delegate,
        private ArgumentScoper $scoper,
        private ToolArgumentValidator $validator,
        private Closure $principalResolver,
    ) {}

    public function description(): Stringable|string
    {
        return $this->delegate->description();
    }

    public function handle(Request $request): Stringable|string
    {
        $scoped = $this->scoper->scope($request->toArray(), ($this->principalResolver)());

        $violations = $this->validator->validate($this->delegate, $scoped);
        if ($violations !== []) {
            throw new ToolArgumentRejection($violations, (string) $this->delegate->description());
        }

        return $this->delegate->handle(new Request($scoped));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate->schema($schema);
    }
}
