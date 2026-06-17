<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

/**
 * One page of firewall rejections plus the cursor to fetch the next page (null = no more rows).
 */
final readonly class FirewallPage
{
    /** @param list<FirewallRejection> $items */
    public function __construct(
        public array $items,
        public ?int $nextCursor = null,
    ) {}
}
