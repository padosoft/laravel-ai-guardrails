<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Contracts\ToolAuthorizer;
use Padosoft\AiGuardrails\Exceptions\ToolNotAuthorized;
use Stringable;

/**
 * Decorator that gates a tool behind the authorization policy (Task E7) BEFORE delegating. Composed
 * around the FirewalledTool by AiGuardrails::guard() when tool_authorization is enabled, so the order
 * is: authorize → re-scope → validate → run. A denial throws ToolNotAuthorized (fail-closed).
 */
final readonly class AuthorizedTool implements Tool
{
    public function __construct(
        private Tool $delegate,
        private ToolAuthorizer $authorizer,
        private string $toolIdentifier,
    ) {}

    public function description(): Stringable|string
    {
        return $this->delegate->description();
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->authorizer->authorize($this->toolIdentifier)) {
            throw new ToolNotAuthorized($this->toolIdentifier);
        }

        return $this->delegate->handle($request);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate->schema($schema);
    }
}
