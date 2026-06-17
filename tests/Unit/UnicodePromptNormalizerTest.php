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
}
