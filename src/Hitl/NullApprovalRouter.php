<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use LogicException;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;

/**
 * Bound when padosoft/laravel-flow is absent or HITL is disabled. It reports itself unavailable so
 * the gated tool applies its configured fallback ('deny' = refuse, 'pass' = execute) rather than
 * attempting to route through a flow that does not exist.
 */
final class NullApprovalRouter implements ApprovalRouter
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function route(string $toolName, string $toolClass, array $arguments, int|string|null $principalId): PendingApproval
    {
        throw new LogicException('Approval routing is unavailable; the gated tool must apply its fallback.');
    }

    public function approve(string $token, array $actor = []): void
    {
        throw new LogicException('Approval routing is unavailable.');
    }

    public function reject(string $token, array $actor = []): void
    {
        throw new LogicException('Approval routing is unavailable.');
    }
}
