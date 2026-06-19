<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Output\OutputStatKind;

interface OutputStatStore
{
    /**
     * Append an output-handling event (Control C) to the immutable counter log.
     *
     * When $detector is non-null, the row also carries a detector name so that
     * PiiRedaction events can be broken down by detector in GET /output/stats.
     * Existing call-sites that omit $detector continue to work unchanged.
     */
    public function record(OutputStatKind $kind, int $count = 1, ?string $detector = null): void;

    /**
     * Summed event counts per kind within the optional [from, to] window (only kinds with events).
     *
     * @return array<string,int> kind value => total
     */
    public function totals(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array;

    /**
     * Per-detector breakdown for PiiRedaction events within the optional [from, to] window.
     *
     * Only rows whose detector is non-null are included. Rows recorded without a detector
     * (e.g. legacy rows or when the pii-redactor does not expose per-detector counts) are
     * silently omitted, so the map is always accurate and never inflated.
     *
     * @return array<string,int> detector_name => count
     */
    public function byDetector(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array;

    /** Total recorded events across all kinds (consumed by the overview counters). */
    public function count(): int;
}
