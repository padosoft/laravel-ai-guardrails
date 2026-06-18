<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use HTMLPurifier;
use Padosoft\AiGuardrails\Contracts\ReportingOutputSanitizer;

/**
 * Allowlist-grade HTML sanitization backed by HTMLPurifier (Task L2) — used in `html_mode=allowlist`
 * when `ezyang/htmlpurifier` is installed. Unlike the built-in `strip_tags` allowlist, HTMLPurifier
 * fully parses the document, so mutation-XSS, malformed/entity-encoded tags, and attribute tricks are
 * handled robustly. The markdown link/image/autolink exfiltration defang is delegated to a
 * markdown-only {@see HtmlMarkdownSanitizer} so behaviour matches the rest of Control C.
 *
 * The optional-vendor reference (`HTMLPurifier`) is confined to this src/Output adapter boundary,
 * built by {@see HtmlSanitizerFactory}; the service provider never references the vendor directly.
 */
final readonly class PurifierHtmlSanitizer implements ReportingOutputSanitizer
{
    public function __construct(
        private HTMLPurifier $purifier,
        private HtmlMarkdownSanitizer $markdownDefanger,
    ) {}

    public function sanitize(string $text): string
    {
        return $this->sanitizeReport($text)->text;
    }

    public function sanitizeReport(string $text): SanitizationReport
    {
        $purified = $this->purifier->purify($text);
        $htmlChanged = $purified !== $text;

        // Reuse the canonical markdown defang (reference links / inline links+images / angle autolinks)
        // on the purified HTML so the exfiltration vectors are neutralised identically to escape mode.
        $report = $this->markdownDefanger->sanitizeReport($purified);

        return new SanitizationReport($report->text, $htmlChanged, $report->markdownChanged);
    }
}
