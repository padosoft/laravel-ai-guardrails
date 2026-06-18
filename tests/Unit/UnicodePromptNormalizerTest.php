<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Screening\UnicodePromptNormalizer;
use Padosoft\AiGuardrails\Tests\TestCase;

final class UnicodePromptNormalizerTest extends TestCase
{
    public function test_nfkc_folds_fullwidth_to_ascii(): void
    {
        // "ｉｇｎｏｒｅ" (fullwidth) → "ignore"
        $out = (new UnicodePromptNormalizer)->normalize("\u{FF49}\u{FF47}\u{FF4E}\u{FF4F}\u{FF52}\u{FF45}");

        self::assertSame('ignore', $out);
    }

    public function test_strips_zero_width_characters(): void
    {
        $out = (new UnicodePromptNormalizer)->normalize("ig\u{200B}no\u{FEFF}re");

        self::assertSame('ignore', $out);
    }

    public function test_casefolds_to_lowercase(): void
    {
        self::assertSame('ignore previous', (new UnicodePromptNormalizer)->normalize('IGNORE Previous'));
    }

    public function test_each_pass_is_individually_toggleable(): void
    {
        // Only zero-width stripping on: case and fullwidth are preserved.
        $normalizer = new UnicodePromptNormalizer(nfkc: false, stripZeroWidth: true, stripControl: false, casefold: false);

        self::assertSame('IGnore', $normalizer->normalize("IG\u{200B}nore"));
    }

    public function test_plain_text_is_unchanged_apart_from_casefold(): void
    {
        $normalizer = new UnicodePromptNormalizer(casefold: false);

        self::assertSame('hello world', $normalizer->normalize('hello world'));
    }

    public function test_strips_soft_hyphen(): void
    {
        // U+00AD soft hyphen is invisible and can split keywords to evade pattern matching.
        $out = (new UnicodePromptNormalizer)->normalize("ig\u{00AD}nore");

        self::assertSame('ignore', $out);
    }

    public function test_strips_combining_grapheme_joiner(): void
    {
        // U+034F combining grapheme joiner is zero-width and can break word boundaries.
        $out = (new UnicodePromptNormalizer)->normalize("ig\u{034F}nore");

        self::assertSame('ignore', $out);
    }

    public function test_strips_unicode_tag_block_characters(): void
    {
        // U+E0000–U+E007F TAG characters were used in invisible-text prompt injection attacks (2024).
        $out = (new UnicodePromptNormalizer)->normalize("ig\u{E006E}ore");

        self::assertSame('igore', $out);
    }

    public function test_strips_directional_isolates_and_invisible_operators(): void
    {
        // U+2066 LEFT-TO-RIGHT ISOLATE (and the rest of the U+2060–U+206F invisible/format block).
        $out = (new UnicodePromptNormalizer)->normalize("ig\u{2066}no\u{2063}re");

        self::assertSame('ignore', $out);
    }

    public function test_nfkc_disabled_does_not_fold_fullwidth(): void
    {
        // Every pass off + nfkc=false → the fullwidth text is returned verbatim. Pins the
        // `$this->nfkc && class_exists(...)` guard: a mutated `||` would NFKC-fold despite nfkc=false.
        $normalizer = new UnicodePromptNormalizer(nfkc: false, stripZeroWidth: false, stripControl: false, casefold: false);

        self::assertSame("\u{FF49}\u{FF47}\u{FF4E}", $normalizer->normalize("\u{FF49}\u{FF47}\u{FF4E}"));
    }
}
