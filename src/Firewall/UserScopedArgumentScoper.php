<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use LogicException;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;

/**
 * Overwrites the configured "owner" argument keys with the authenticated principal id,
 * server-side, so the model can never act on another user's resources via a chosen id
 * (confused-deputy / IDOR). Owner keys are injected even when the model omitted them.
 *
 * When schema types are supplied (by FirewalledTool), scoping is restricted to owner keys the
 * tool actually declares and the principal is coerced to the declared type (e.g. an integer
 * owner field gets an int principal, not a string that would fail validation).
 *
 * Task 2 rewrites top-level keys only; Task E7 adds recursive (nested) rewriting.
 */
final readonly class UserScopedArgumentScoper implements ArgumentScoper
{
    /** @param list<string> $ownerKeys */
    public function __construct(private array $ownerKeys = []) {}

    public function scope(array $arguments, int|string|null $principalId, array $schemaTypes = []): array
    {
        if ($principalId === null) {
            // If any configured owner key is present in the arguments we cannot re-scope it,
            // so we refuse rather than silently pass attacker-controlled values through.
            foreach ($this->ownerKeys as $key) {
                if (array_key_exists($key, $arguments)) {
                    throw new LogicException(
                        "Cannot scope owner key [{$key}]: no authenticated principal. ".
                        'Ensure the request is authenticated before invoking a firewalled tool.'
                    );
                }
            }

            return $arguments;
        }

        $schemaAware = $schemaTypes !== [];

        foreach ($this->ownerKeys as $key) {
            // With schema context, only scope owner keys the tool declares — injecting one it does
            // not accept would be rejected by the validator as an unknown argument.
            if ($schemaAware && ! array_key_exists($key, $schemaTypes)) {
                continue;
            }

            $arguments[$key] = $this->coerce($principalId, $schemaAware ? $schemaTypes[$key] : 'string');
        }

        return $arguments;
    }

    /**
     * @param  string|array<int,string>|null  $type
     */
    private function coerce(int|string $principalId, string|array|null $type): int|string
    {
        $isInteger = $type === 'integer'
            || (is_array($type) && in_array('integer', $type, true) && ! in_array('string', $type, true));

        return $isInteger ? (int) $principalId : (string) $principalId;
    }
}
