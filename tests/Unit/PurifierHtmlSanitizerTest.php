<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Output\HtmlSanitizerFactory;
use Padosoft\AiGuardrails\Output\PurifierHtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * L2 — HTMLPurifier-backed allowlist sanitization. Exercised through the factory so the real
 * HTMLPurifier configuration (allowed tags, no cache) is used, matching production wiring.
 */
final class PurifierHtmlSanitizerTest extends TestCase
{
    private function sanitizer(): PurifierHtmlSanitizer
    {
        $sanitizer = HtmlSanitizerFactory::make('allowlist', sanitizeHtml: true, neutralizeMarkdown: true, purifierAvailable: true);
        self::assertInstanceOf(PurifierHtmlSanitizer::class, $sanitizer);

        return $sanitizer;
    }

    public function test_strips_script_and_event_handler_xss(): void
    {
        $out = $this->sanitizer()->sanitize('<b>hi</b><script>alert(1)</script><img src=x onerror=alert(1)>');

        self::assertStringContainsString('<b>hi</b>', $out); // safe tag kept
        self::assertStringNotContainsString('<script', $out);
        self::assertStringNotContainsString('onerror', $out);
        self::assertStringNotContainsString('<img', $out); // img not in the allowed set
    }

    public function test_strips_disallowed_attributes_from_allowed_tags(): void
    {
        $out = $this->sanitizer()->sanitize('<p style="x" onclick="evil()">text</p>');

        self::assertStringContainsString('<p>text</p>', $out);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('style', $out);
    }

    public function test_strips_all_default_attributes_from_allowed_tags(): void
    {
        // HTMLPurifier permits class/id/lang/title by default unless the empty-bracket syntax
        // is used in HTML.Allowed — verify they are also stripped.
        $out = $this->sanitizer()->sanitize('<p class="exfil" id="leak" lang="en" title="t">text</p>');

        self::assertStringContainsString('text', $out);
        self::assertStringNotContainsString('class=', $out);
        self::assertStringNotContainsString('id=', $out);
        self::assertStringNotContainsString('lang=', $out);
        self::assertStringNotContainsString('title=', $out);
    }

    public function test_neutralizes_malformed_and_entity_encoded_tags(): void
    {
        // A mutation/entity-encoded payload that strip_tags would mishandle — HTMLPurifier parses it out.
        $out = $this->sanitizer()->sanitize('<scr<script>ipt>alert(1)</script><a href="javascript:alert(1)">x</a>');

        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringNotContainsString('<script', $out);
        self::assertStringNotContainsString('<a', $out); // links removed (no exfiltration target)
    }

    public function test_still_defangs_markdown_exfiltration(): void
    {
        // HTML is purified AND the markdown link/image defang still runs on the result.
        $out = $this->sanitizer()->sanitize('see [click](http://evil.test/leak) and ![x](http://evil.test/p)');

        self::assertStringNotContainsString('evil.test', $out);
        self::assertStringContainsString('(blocked)', $out);
    }

    public function test_report_flags_html_and_markdown_changes_separately(): void
    {
        $report = $this->sanitizer()->sanitizeReport('<script>x</script> ![a](http://evil.test/p)');

        self::assertTrue($report->htmlChanged);
        self::assertTrue($report->markdownChanged);

        $clean = $this->sanitizer()->sanitizeReport('a perfectly clean sentence');
        self::assertFalse($clean->htmlChanged);
        self::assertFalse($clean->markdownChanged);
        self::assertSame('a perfectly clean sentence', $clean->text);
    }

    public function test_benign_html_with_bare_ampersand_does_not_set_html_changed(): void
    {
        // HTMLPurifier entity-encodes bare & inside tag content, which would make $purified !== $text
        // and produce a false-positive htmlChanged flag. Verify the report is accurate for clean input.
        $report = $this->sanitizer()->sanitizeReport('<p>price is 10 &amp; 20</p>');

        self::assertFalse($report->htmlChanged, 'Already-encoded entities must not flip htmlChanged');
    }
}
