<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

/**
 * One page of gated tool calls plus the cursor for the next (null = no more rows).
 */
final readonly class GatedToolCallPage
{
    /** @param list<GatedToolCall> $items */
    public function __construct(
        public array $items,
        public ?int $nextCursor = null,
    ) {}
}
