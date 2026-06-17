<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

/**
 * One page of audit rows plus the cursor to fetch the next page (null = no more rows).
 */
final readonly class AuditPage
{
    /** @param list<InjectionAttempt> $items */
    public function __construct(
        public array $items,
        public ?int $nextCursor = null,
    ) {}
}
