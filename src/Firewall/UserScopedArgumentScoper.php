<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Padosoft\AiGuardrails\Contracts\ArgumentScoper;

/**
 * Overwrites the configured "owner" argument keys with the authenticated principal id,
 * server-side, so the model can never act on another user's resources via a chosen id
 * (confused-deputy / IDOR). Owner keys are injected even when the model omitted them.
 *
 * Task 2 rewrites top-level keys only; Task E7 adds recursive (nested) rewriting.
 */
final readonly class UserScopedArgumentScoper implements ArgumentScoper
{
    /** @param list<string> $ownerKeys */
    public function __construct(private array $ownerKeys = []) {}

    public function scope(array $arguments, int|string|null $principalId): array
    {
        if ($principalId === null) {
            return $arguments;
        }

        foreach ($this->ownerKeys as $key) {
            $arguments[$key] = (string) $principalId;
        }

        return $arguments;
    }
}
