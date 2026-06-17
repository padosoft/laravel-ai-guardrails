<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use DateTimeImmutable;

/**
 * The result of parking a destructive tool call for human approval: the plain-text approval token
 * (shown to the operator), the flow run id, the tool, when the token expires, and the scoped args.
 */
final readonly class PendingApproval
{
    /** @param array<string,mixed> $scopedArguments */
    public function __construct(
        public string $token,
        public string $runId,
        public string $toolName,
        public DateTimeImmutable $expiresAt,
        public array $scopedArguments,
    ) {}
}
