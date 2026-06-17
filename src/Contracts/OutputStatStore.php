<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Output\OutputStatKind;

interface OutputStatStore
{
    /** Append an output-handling event (Control C) to the immutable counter log. */
    public function record(OutputStatKind $kind, int $count = 1): void;

    /**
     * Summed event counts per kind within the optional [from, to] window (only kinds with events).
     *
     * @return array<string,int> kind value => total
     */
    public function totals(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array;

    /** Total recorded events across all kinds (consumed by the overview counters). */
    public function count(): int;
}
