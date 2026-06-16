<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Padosoft\AiGuardrails\Contracts\ArgumentScoper;

/**
 * No-op scoper bound when tool_firewall.enabled = false.
 * Returns arguments untouched; never throws.
 */
final class PassthroughArgumentScoper implements ArgumentScoper
{
    public function scope(array $arguments, int|string|null $principalId, array $schemaTypes = []): array
    {
        return $arguments;
    }
}
