<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Events\ToolArgumentRejected;
use Padosoft\AiGuardrails\Exceptions\ToolArgumentRejection;
use Padosoft\AiGuardrails\Support\ControlMode;
use Stringable;

/**
 * Decorator over a laravel/ai Tool that enforces the firewall before delegating:
 * (1) re-scope owner keys to the authenticated principal, (2) validate the scoped
 * arguments against the tool's own schema, (3) reject (throw) on any violation,
 * otherwise (4) delegate with the scoped arguments. Untrusted-input posture.
 *
 * Monitor-mode security note: in `monitor` the owner-key re-scoping IS applied (the
 * principal's value always overwrites model-supplied owner keys), but schema violations
 * — unknown fields, type mismatches — are NOT stripped; they reach the delegate tool.
 * This is intentional for shadow rollout: the `FirewallRejectionStore` records every
 * violation so operators can see what enforcement would block. Only use `monitor` when
 * the downstream delegate is known to ignore unrecognised arguments.
 */
final readonly class FirewalledTool implements Tool
{
    /** @param Closure():(int|string|null) $principalResolver */
    public function __construct(
        private Tool $delegate,
        private ArgumentScoper $scoper,
        private ToolArgumentValidator $validator,
        private Closure $principalResolver,
        private ?FirewallRejectionStore $rejectionStore = null,
        private ControlMode $mode = ControlMode::Enforce,
        private ?Dispatcher $events = null,
    ) {}

    public function description(): Stringable|string
    {
        return $this->delegate->description();
    }

    public function handle(Request $request): Stringable|string
    {
        $principal = ($this->principalResolver)();

        $scoped = $this->scoper->scope(
            $request->toArray(),
            $principal,
            $this->schemaTypes(),
        );

        $violations = $this->validator->validate($this->delegate, $scoped);
        if ($violations !== []) {
            $description = (string) $this->delegate->description();

            $rejection = new FirewallRejection(
                $description,
                $principal !== null ? (string) $principal : null,
                $violations,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );

            // Fire-and-forget: a store failure (DB down, etc.) must never suppress the rejection.
            try {
                $this->rejectionStore?->record($rejection);
            } catch (\Throwable $e) {
                // Enforcement is independent of persistence — log (don't rethrow) so the missing
                // firewall rejection record is observable instead of silently lost.
                Log::warning('laravel-ai-guardrails: failed to record a firewall rejection.', [
                    'tool' => $description,
                    'exception' => $e,
                ]);
            }

            // Emit from the same path as the record (independent of persistence success).
            // $enforced=true when the call will be blocked below; false in monitor (proceeds).
            $this->events?->dispatch(new ToolArgumentRejected($rejection, $this->mode->enforces()));

            // enforce → block; monitor → record + pass through. Owner-key re-scoping is preserved in
            // both modes (the principal's value always wins for owner keys). Schema-violating args
            // (unknown fields, type mismatches) still reach the delegate in monitor mode — see the
            // class-level security note. The rejection is recorded by rejectionStore either way.
            if ($this->mode->enforces()) {
                throw new ToolArgumentRejection($violations, $description);
            }
        }

        return $this->delegate->handle(new Request($scoped));
    }

    /**
     * The delegate's schema property names mapped to their declared JSON-schema type, used by the
     * scoper to restrict owner-key re-scoping to declared keys and coerce the principal's type.
     *
     * @return array<string,string|array<int,string>|null>
     */
    private function schemaTypes(): array
    {
        $factory = new JsonSchemaTypeFactory;
        $schema = $factory->object($this->delegate->schema($factory))->toArray();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $types = [];
        foreach ($properties as $key => $definition) {
            $types[$key] = is_array($definition) ? ($definition['type'] ?? null) : null;
        }

        return $types;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate->schema($schema);
    }
}
