<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Padosoft\AiGuardrails\Contracts\OutputSanitizer;

/**
 * Treats model output as untrusted text. In the default 'escape' mode it HTML-escapes the whole
 * string (so any tags render inert) and neutralizes the markdown link/image exfiltration vector
 * (`![alt](url)` / `[text](url)` — used to make a victim's client fetch an attacker URL) plus angle
 * autolinks. Deterministic; no LLM. Task E8 adds an allowlist mode that preserves safe rendered
 * markup instead of escaping everything.
 */
final readonly class HtmlMarkdownSanitizer implements OutputSanitizer
{
    public function __construct(
        private bool $sanitizeHtml = true,
        private bool $neutralizeMarkdown = true,
    ) {}

    public function sanitize(string $text): string
    {
        if ($this->sanitizeHtml) {
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false);
        }

        if ($this->neutralizeMarkdown) {
            // Drop the URL target of inline markdown links/images: `![alt](url)` / `[text](url)`
            // → `![alt](blocked)` / `[text](blocked)`. This kills the data-exfiltration vector
            // (and any javascript:/data: URI it might carry) while keeping the visible text.
            $text = preg_replace('/(!?\[[^\]]*\])\([^)]*\)/', '$1(blocked)', $text) ?? $text;

            // Angle autolinks `<scheme://...>` (match before/after HTML-escaping of the angle brackets).
            $text = preg_replace('/(?:<|&lt;)\s*[a-z][a-z0-9+.-]*:\/\/[^>\s]*\s*(?:>|&gt;)/i', '(blocked)', $text) ?? $text;
        }

        return $text;
    }
}
