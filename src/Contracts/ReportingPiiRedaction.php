<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Output\PiiRedactionReport;

/**
 * A PiiRedaction implementation that can also report which detectors fired and how many matches
 * each produced, so Control C can record per-detector output stats (data.counts.pii.by_detector).
 *
 * Optional: GuardrailOutputMiddleware checks for this interface at runtime and falls back to the
 * plain PiiRedaction::redact() path (recording no detector breakdown) when absent.
 *
 * NOTE: implementations call the underlying engine TWICE — scan() then redact() — to obtain the
 * detector map. This is acceptable because the redactor is deterministic (same input → same
 * detections) and the overhead is proportional to the text length, not the number of detectors.
 * When a single-pass API becomes available upstream, swap the implementation in RealPiiRedaction
 * without touching any other code.
 */
interface ReportingPiiRedaction extends PiiRedaction
{
    /**
     * Redact PII from $text and return both the neutralised string and the per-detector counts.
     */
    public function redactReport(string $text): PiiRedactionReport;
}
