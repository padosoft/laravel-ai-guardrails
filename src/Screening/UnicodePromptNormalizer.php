<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

use Normalizer;
use Padosoft\AiGuardrails\Contracts\PromptNormalizer;

/**
 * Defeats trivial screening evasion by folding the prompt to a canonical form before matching:
 * Unicode NFKC (fullwidth/homoglyph → ASCII), zero-width character stripping, control-character
 * stripping, and unicode-aware lower-casing. Degrades gracefully if the intl extension is absent
 * (NFKC is skipped; the other passes still run). All PCRE uses the /u flag.
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
            // ZWSP..RLM, word-joiner, BOM/ZWNBSP, Mongolian vowel separator.
            $text = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{180E}]/u', '', $text) ?? $text;
        }

        if ($this->stripControl) {
            // C0/C1 controls except the common whitespace tab/newline/carriage-return.
            $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $text) ?? $text;
        }

        if ($this->casefold) {
            $text = mb_strtolower($text);
        }

        return $text;
    }
}
