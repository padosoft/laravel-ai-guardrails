<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

/**
 * Immutable result of a redactReport() call: the neutralised text plus a per-detector hit map.
 *
 * $countsByDetector mirrors the structure of DetectionReport::countsByDetector():
 * detector_name => match count (only detectors with ≥ 1 match appear).
 */
final readonly class PiiRedactionReport
{
    /**
     * @param  array<string,int>  $countsByDetector  detector_name => match count
     */
    public function __construct(
        public string $text,
        public array $countsByDetector,
    ) {}

    public function hadRedactions(): bool
    {
        return $this->countsByDetector !== [];
    }
}
