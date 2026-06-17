<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use Illuminate\Support\Facades\Log;
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
        private string $htmlMode = 'escape',
    ) {}

    public function sanitize(string $text): string
    {
        if ($this->sanitizeHtml) {
            $text = $this->htmlMode === 'allowlist'
                ? $this->allowlist($text)
                : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false);
        }

        if ($this->neutralizeMarkdown) {
            // Reference-link definitions: `[label]: url` (and `[label]: url "title"` etc.).
            // These are invisible to the inline-link regex and would allow an attacker to define
            // exfiltration targets for `[text][label]` usages anywhere in the document.
            // Must run before the inline-link pass. Safe after HTML-escaping because `[`, `]`, `:`
            // are not HTML-special chars and are preserved verbatim.
            // ` {0,3}` allows the up-to-3-spaces of indentation CommonMark permits before a definition.
            $text = $this->defang('/^ {0,3}\[[^\]]+\]:\s*\S.*/m', '[ref]: (blocked)', $text);

            // Inline links/images: `[text](url)` / `![alt](url)` → keep visible text, drop URL.
            $text = $this->defang('/(!?\[[^\]]*\])\([^)]*\)/', '$1(blocked)', $text);

            // Angle autolinks `<scheme:...>`. Does NOT require `://` so that dangerous bare-colon
            // schemes (`javascript:alert(1)`, `data:text/html,...`, `vbscript:`) are also blocked.
            // Matches both raw `<>` and HTML-entity-escaped `&lt;&gt;` forms (the latter produced
            // when sanitizeHtml is true). Backtracking lets `[^\s>]*` stop before the closing `&gt;`.
            $text = $this->defang('/(?:<|&lt;)\s*[a-z][a-z0-9+.-]*:[^\s>]*\s*(?:>|&gt;)/i', '(blocked)', $text);
        }

        return $text;
    }

    /**
     * Apply a defang replacement, failing CLOSED on a PCRE error: if preg_replace() returns null
     * (e.g. backtrack-limit exhaustion or a bad-UTF-8 subject), do NOT fall back to the original
     * text (that would leave the exfiltration syntax intact). Instead strip the structural
     * characters that make up link/autolink targets so no such syntax can survive.
     */
    /**
     * Allowlist mode (Task E8): instead of escaping everything, keep a tiny set of safe inline
     * formatting tags and strip ALL of their attributes (so no `onclick`/`style`/`href` survives)
     * and every other tag. Links/images/autolinks are removed entirely (no exfiltration target).
     * This is NOT HTMLPurifier-grade — for rendering rich untrusted HTML, use a dedicated sanitizer.
     */
    private function allowlist(string $text): string
    {
        $allowed = ['b', 'i', 'em', 'strong', 'code', 'br', 'p', 'ul', 'ol', 'li'];

        // Decode HTML entities first so entity-encoded tags (e.g. `&#x3C;script&#x3E;`) are revealed
        // and then removed by strip_tags, rather than surviving as a latent XSS payload.
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $stripped = strip_tags($decoded, '<'.implode('><', $allowed).'>');

        // Strip ALL attributes, but ONLY from the allowed tags, and require the tag name to follow
        // `<` immediately (no whitespace). This prevents malformed text that strip_tags left alone
        // (e.g. `< script >…< /script >`) from being normalized BACK into a real disallowed tag.
        $pattern = '/<(\/?)('.implode('|', $allowed).')\b[^>]*>/i';

        return preg_replace($pattern, '<$1$2>', $stripped) ?? '';
    }

    private function defang(string $pattern, string $replacement, string $text): string
    {
        $result = preg_replace($pattern, $replacement, $text);

        if ($result === null) {
            Log::warning('laravel-ai-guardrails: markdown defang pattern errored; failing closed.', [
                'preg_error' => preg_last_error_msg(),
            ]);

            // Strip every character that forms a link/autolink/reference target so no exfiltration
            // syntax (inline `[t](url)`, reference `[ref]: url`, autolink `<scheme:...>`) survives.
            return str_replace(['(', ')', '<', '>', '[', ']', '&lt;', '&gt;'], '', $text);
        }

        return $result;
    }
}
