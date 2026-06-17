<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Illuminate\Support\Facades\Log;
use Normalizer;
use Padosoft\AiGuardrails\Contracts\PromptNormalizer;

/**
 * Defeats trivial screening evasion by folding the prompt to a canonical form before matching:
 * Unicode NFKC (fullwidth characters → ASCII), zero-width/invisible character stripping,
 * control-character stripping, and unicode-aware lower-casing.
 *
 * Degrades gracefully if the intl extension is absent (NFKC is skipped; the other passes still run).
 * All PCRE uses the /u flag.
 *
 * KNOWN GAP: NFKC handles fullwidth Latin (ｉｇｎｏｒｅ → ignore) but does NOT collapse cross-script
 * lookalikes (Cyrillic а ≠ Latin a, Greek ο ≠ Latin o, IPA homoglyphs, etc.). Operators writing
 * patterns should be aware that such characters pass through as-is. See Unicode confusables /
 * skeleton algorithm for future hardening.
 *
 * PATTERN AUTHORING NOTE: All patterns are matched against the casefolded, NFKC-normalized form.
 * Write patterns in lowercase, or add the /i flag — case-sensitive patterns without /i will miss
 * mixed-case inputs after casefold normalization.
 */
final readonly class UnicodePromptNormalizer implements PromptNormalizer
{
    public function __construct(
        private bool $nfkc = true,
        private bool $stripZeroWidth = true,
        private bool $stripControl = true,
        private bool $casefold = true,
    ) {}

    public function normalize(string $prompt): string
    {
        $text = $prompt;

        if ($this->nfkc && class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        if ($this->stripZeroWidth) {
            // ZWSP..RLM, word-joiner, BOM/ZWNBSP, Mongolian vowel separator, soft hyphen,
            // combining grapheme joiner, and Unicode TAG block (U+E0000-U+E007F) — the TAG
            // block is used in invisible-text prompt injection attacks.
            $result = preg_replace(
                '/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{180E}\x{00AD}\x{034F}\x{E0000}-\x{E007F}]/u',
                '',
                $text,
            );
            if ($result === null) {
                Log::warning('laravel-ai-guardrails: zero-width strip regex failed; normalization pass skipped.', [
                    'preg_error' => preg_last_error_msg(),
                ]);
            } else {
                $text = $result;
            }
        }

        if ($this->stripControl) {
            // C0/C1 controls except the common whitespace tab/newline/carriage-return.
            $result = preg_replace(
                '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u',
                '',
                $text,
            );
            if ($result === null) {
                Log::warning('laravel-ai-guardrails: control-char strip regex failed; normalization pass skipped.', [
                    'preg_error' => preg_last_error_msg(),
                ]);
            } else {
                $text = $result;
            }
        }

        if ($this->casefold) {
            $text = mb_strtolower($text, 'UTF-8');
        }

        return $text;
    }
}
