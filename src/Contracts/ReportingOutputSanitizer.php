<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Output\SanitizationReport;

/**
 * An OutputSanitizer that can also report which neutralisations changed the text, so Control C can
 * record per-kind output stats. Optional: the middleware falls back to plain sanitize() otherwise.
 */
interface ReportingOutputSanitizer extends OutputSanitizer
{
    public function sanitizeReport(string $text): SanitizationReport;
}
