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
 * Cross-script homoglyphs: NFKC handles fullwidth Latin (ｉｇｎｏｒｅ → ignore) but does NOT collapse
 * cross-alphabet look-alikes (Cyrillic а ≠ Latin a, Greek ο ≠ Latin o). The `foldConfusables` pass
 * (see {@see ConfusablesFolder}) closes that gap by mapping a curated set of those look-alikes to a
 * Latin skeleton before matching. It is a lossy, one-way fold applied on the match path only.
 *
 * PATTERN AUTHORING NOTE: All patterns are matched against the casefolded, NFKC-normalized form.
 * Write patterns in lowercase, or add the /i flag — case-sensitive patterns without /i will miss
 * mixed-case inputs after casefold normalization.
 */
final class UnicodePromptNormalizer implements PromptNormalizer
{
    private readonly ConfusablesFolder $resolvedFolder;

    public function __construct(
        private readonly bool $nfkc = true,
        private readonly bool $stripZeroWidth = true,
        private readonly bool $stripControl = true,
        private readonly bool $casefold = true,
        private readonly bool $foldConfusables = true,
        ?ConfusablesFolder $confusablesFolder = null,
    ) {
        $this->resolvedFolder = $confusablesFolder ?? new ConfusablesFolder;
    }

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
            // ZWSP..RLM; the full U+2060–U+206F invisible/format block (word joiner, invisible math
            // operators, directional isolates LRI/RLI/FSI/PDI, deprecated format chars); BOM/ZWNBSP;
            // Mongolian vowel separator; soft hyphen; combining grapheme joiner; and the Unicode TAG
            // block (U+E0000-U+E007F) used in invisible-text prompt injection attacks.
            $result = preg_replace(
                '/[\x{200B}-\x{200F}\x{2060}-\x{206F}\x{FEFF}\x{180E}\x{00AD}\x{034F}\x{E0000}-\x{E007F}]/u',
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

        if ($this->foldConfusables) {
            // Map cross-script homoglyphs (Cyrillic/Greek look-alikes) to a Latin skeleton so an
            // attacker can't slip "ignоre" (Cyrillic о) past the patterns. Runs before casefold so the
            // skeleton (lowercase Latin) and any native Latin are lower-cased together below.
            $text = $this->resolvedFolder->fold($text);
        }

        if ($this->casefold) {
            $text = mb_strtolower($text, 'UTF-8');
        }

        return $text;
    }
}
