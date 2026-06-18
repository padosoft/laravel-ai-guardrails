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
 * Depth (`tool_authorization.owner_key_depth`, Task E7): `top_level` rewrites only the top-level
 * arguments (Task 2 behaviour). `recursive` ALSO overwrites any owner key found at any nesting
 * depth in the argument tree — but only OVERWRITES keys already present (it never injects new owner
 * keys into nested objects, which the tool schema does not describe).
 */
final readonly class UserScopedArgumentScoper implements ArgumentScoper
{
    /** @param list<string> $ownerKeys */
    public function __construct(
        private array $ownerKeys = [],
        private bool $recursive = false,
    ) {}

    public function scope(array $arguments, int|string|null $principalId, array $schemaTypes = []): array
    {
        if ($principalId === null) {
            // If any configured owner key is present in the arguments we cannot re-scope it,
            // so we refuse rather than silently pass attacker-controlled values through. In
            // recursive mode the whole tree is checked (a nested owner key is just as dangerous).
            foreach ($this->ownerKeys as $key) {
                if ($this->keyPresent($arguments, $key)) {
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

        if ($this->recursive) {
            foreach ($arguments as $key => $value) {
                if (is_array($value)) {
                    $arguments[$key] = $this->scopeNested($value, $principalId);
                }
            }
        }

        return $arguments;
    }

    /**
     * Whether $key appears at the top level (always) or anywhere in the tree (recursive mode).
     *
     * @param  array<int|string,mixed>  $arguments
     */
    private function keyPresent(array $arguments, string $key): bool
    {
        if (array_key_exists($key, $arguments)) {
            return true;
        }

        if ($this->recursive) {
            foreach ($arguments as $value) {
                if (is_array($value) && $this->keyPresent($value, $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Overwrite owner keys inside a nested array node, then recurse into its array values. Nested
     * values carry no schema, so the principal is coerced to a string; only scalar owner-key values
     * are rewritten (an owner key whose value is itself an array is structurally not a principal id).
     *
     * @param  array<int|string,mixed>  $node
     * @return array<int|string,mixed>
     */
    private function scopeNested(array $node, int|string $principalId): array
    {
        foreach ($this->ownerKeys as $ownerKey) {
            if (array_key_exists($ownerKey, $node) && ! is_array($node[$ownerKey])) {
                $node[$ownerKey] = (string) $principalId;
            }
        }

        foreach ($node as $k => $value) {
            if (is_array($value)) {
                $node[$k] = $this->scopeNested($value, $principalId);
            }
        }

        return $node;
    }

    /**
     * @param  string|array<int,string>|null  $type
     */
    private function coerce(int|string $principalId, string|array|null $type): int|string
    {
        $isInteger = $type === 'integer'
            || (is_array($type) && in_array('integer', $type, true) && ! in_array('string', $type, true));

        // Only cast to int when the principal is actually numeric. A non-numeric principal (e.g. a
        // UUID) must NOT be silently coerced to 0 — keep it as a string so the integer schema
        // validation rejects it (fail-closed) rather than scoping to the wrong (zero) principal.
        return $isInteger && is_numeric($principalId) ? (int) $principalId : (string) $principalId;
    }
}
