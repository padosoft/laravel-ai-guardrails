<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Padosoft\AiGuardrails\Contracts\OutputSanitizer;

/**
 * Treats model output as untrusted text. In the default 'escape' mode it HTML-escapes the whole
 * string (so any tags render inert) and neutralizes the markdown link/image exfiltration vector
 * (`![alt](url)` / `[text](url)` — used to make a victim's client fetch an attacker URL) plus
 * reference-link definitions and angle autolinks (including `javascript:` / `data:` URIs that lack
 * `//`). Deterministic; no LLM. Task E8 adds an allowlist mode that preserves safe rendered markup
 * instead of escaping everything.
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
            // Reference-link definitions: `[label]: url` (and `[label]: url "title"` etc.).
            // These are invisible to the inline-link regex and would allow an attacker to define
            // exfiltration targets for `[text][label]` usages anywhere in the document.
            // Must run before the inline-link pass. Safe after HTML-escaping because `[`, `]`, `:`
            // are not HTML-special chars and are preserved verbatim.
            $text = preg_replace('/^\[[^\]]+\]:\s*\S.*/m', '[ref]: (blocked)', $text) ?? $text;

            // Inline links/images: `[text](url)` / `![alt](url)` → keep visible text, drop URL.
            $text = preg_replace('/(!?\[[^\]]*\])\([^)]*\)/', '$1(blocked)', $text) ?? $text;

            // Angle autolinks `<scheme:...>`. Does NOT require `://` so that dangerous bare-colon
            // schemes (`javascript:alert(1)`, `data:text/html,...`, `vbscript:`) are also blocked.
            // Matches both raw `<>` and HTML-entity-escaped `&lt;&gt;` forms (the latter produced
            // when sanitizeHtml is true). Backtracking lets `[^\s>]*` stop before the closing `&gt;`.
            $text = preg_replace('/(?:<|&lt;)\s*[a-z][a-z0-9+.-]*:[^\s>]*\s*(?:>|&gt;)/i', '(blocked)', $text) ?? $text;
        }

        return $text;
    }
}
