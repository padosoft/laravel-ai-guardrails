<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Output\HtmlMarkdownSanitizer;
use Padosoft\AiGuardrails\Output\HtmlSanitizerFactory;
use Padosoft\AiGuardrails\Output\PurifierHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerFactoryTest extends TestCase
{
    public function test_allowlist_with_purifier_available_uses_htmlpurifier(): void
    {
        $sanitizer = HtmlSanitizerFactory::make('allowlist', sanitizeHtml: true, neutralizeMarkdown: true, purifierAvailable: true);

        self::assertInstanceOf(PurifierHtmlSanitizer::class, $sanitizer);
    }

    public function test_allowlist_without_purifier_falls_back_to_builtin(): void
    {
        $sanitizer = HtmlSanitizerFactory::make('allowlist', sanitizeHtml: true, neutralizeMarkdown: true, purifierAvailable: false);

        self::assertInstanceOf(HtmlMarkdownSanitizer::class, $sanitizer);
    }

    public function test_escape_mode_always_uses_the_builtin_sanitizer(): void
    {
        $sanitizer = HtmlSanitizerFactory::make('escape', sanitizeHtml: true, neutralizeMarkdown: true, purifierAvailable: true);

        self::assertInstanceOf(HtmlMarkdownSanitizer::class, $sanitizer);
    }

    public function test_disabled_html_uses_the_builtin_sanitizer_even_in_allowlist_mode(): void
    {
        $sanitizer = HtmlSanitizerFactory::make('allowlist', sanitizeHtml: false, neutralizeMarkdown: true, purifierAvailable: true);

        self::assertInstanceOf(HtmlMarkdownSanitizer::class, $sanitizer);
    }
}
