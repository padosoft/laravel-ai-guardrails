<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Padosoft\AiGuardrails\Contracts\ReportingPiiRedaction;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * Adapter that composes padosoft/laravel-pii-redactor. Bound only when that package is installed
 * (the provider guards with class_exists); otherwise the package degrades to NullPiiRedaction.
 *
 * Implements ReportingPiiRedaction so Control C can record per-detector PII stats
 * (data.counts.pii.by_detector). The engine is called twice: scan() to collect the per-detector
 * map, then redact() for the actual substitution. Both passes are deterministic on the same input.
 */
final readonly class RealPiiRedaction implements ReportingPiiRedaction
{
    public function __construct(private RedactorEngine $engine) {}

    public function redact(string $text): string
    {
        return $this->engine->isEnabled() ? $this->engine->redact($text) : $text;
    }

    public function redactReport(string $text): PiiRedactionReport
    {
        if (! $this->engine->isEnabled() || $text === '') {
            return new PiiRedactionReport($text, []);
        }

        // scan() + redact() = two passes. Both are deterministic, so the counts from the first pass
        // exactly match the substitutions applied in the second. See ReportingPiiRedaction for the
        // rationale and the planned upgrade path to a single-pass API.
        $countsByDetector = $this->engine->scan($text)->countsByDetector();
        $redacted = $this->engine->redact($text);

        return new PiiRedactionReport($redacted, $countsByDetector);
    }
}
