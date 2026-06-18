<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Events;

use DateTimeImmutable;

/**
 * Control D — a destructive tool call was parked for human approval (enforce mode). Dispatched right
 * after the approval router issues a pending-approval reference. Carries the non-secret run reference
 * (never the approval token) plus the tool name and principal so the host can notify an approver.
 */
final readonly class DestructiveToolRouted
{
    public function __construct(
        public string $toolName,
        public int|string|null $principalId,
        public string $runId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
