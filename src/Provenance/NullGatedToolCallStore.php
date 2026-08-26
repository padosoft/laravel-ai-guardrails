<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Padosoft\AiGuardrails\Contracts\GatedToolCallStore;

/**
 * The default. Control P still gates; it just keeps no history, exactly
 * like the firewall's own null store.
 */
final class NullGatedToolCallStore implements GatedToolCallStore
{
    public function record(GatedToolCall $call): void
    {
        // no-op
    }

    public function query(GatedToolCallFilters $filters): GatedToolCallPage
    {
        return new GatedToolCallPage([]);
    }

    public function count(): int
    {
        return 0;
    }
}
