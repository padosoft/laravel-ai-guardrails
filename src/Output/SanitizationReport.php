<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

/**
 * Result of a reporting sanitizer pass: the cleaned text plus which neutralisations actually changed
 * it, so Control C can count html_stripped / markdown_sanitized events (GET /output/stats).
 */
final readonly class SanitizationReport
{
    public function __construct(
        public string $text,
        public bool $htmlChanged,
        public bool $markdownChanged,
    ) {}
}
