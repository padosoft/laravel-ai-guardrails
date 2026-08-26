<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Provenance\GatedToolCall;
use Padosoft\AiGuardrails\Provenance\GatedToolCallFilters;
use Padosoft\AiGuardrails\Provenance\GatedToolCallPage;

interface GatedToolCallStore
{
    /** Append a Control P decision (enforced or observed) to the immutable log. */
    public function record(GatedToolCall $call): void;

    /** Filtered, keyset-paginated query for the admin list (GET /provenance). */
    public function query(GatedToolCallFilters $filters): GatedToolCallPage;

    /** Total recorded decisions (consumed by the overview counters). */
    public function count(): int;
}
