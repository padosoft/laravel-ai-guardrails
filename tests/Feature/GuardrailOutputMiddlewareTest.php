<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Output\ArrayOutputStatStore;
use Padosoft\AiGuardrails\Output\GuardrailOutputMiddleware;
use Padosoft\AiGuardrails\Output\HtmlMarkdownSanitizer;
use Padosoft\AiGuardrails\Output\NullPiiRedaction;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Tests\Doubles\AgentPromptFactory;
use Padosoft\AiGuardrails\Tests\Doubles\AgentResponseFactory;
use Padosoft\AiGuardrails\Tests\TestCase;

final class GuardrailOutputMiddlewareTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_rewrites_response_text_with_sanitized_output(): void
    {
        $middleware = new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, new NullPiiRedaction);

        $response = $middleware->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => AgentResponseFactory::make('<script>x</script> ![img](http://evil.test/leak)'),
        );

        self::assertStringContainsString('&lt;script&gt;', $response->text);
        self::assertStringNotContainsString('evil.test', $response->text);
    }

    public function test_composes_sanitize_then_pii_redaction(): void
    {
        $pii = new class implements PiiRedaction
        {
            public function redact(string $text): string
            {
                return str_replace('john@example.com', '[email]', $text);
            }
        };

        $middleware = new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, $pii);

        $response = $middleware->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => AgentResponseFactory::make('contact john@example.com'),
        );

        self::assertStringContainsString('[email]', $response->text);
    }

    public function test_sanitizes_structured_response_fields_recursively(): void
    {
        $middleware = new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, new NullPiiRedaction);

        $structured = new StructuredAgentResponse(
            'inv-1',
            ['summary' => '<script>x</script>', 'nested' => ['link' => '![a](http://evil.test/leak)']],
            'plain <b>text</b>',
            new Usage,
            new Meta,
        );

        $response = $middleware->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => $structured,
        );

        self::assertInstanceOf(StructuredAgentResponse::class, $response);
        self::assertStringContainsString('&lt;b&gt;', $response->text); // text field escaped
        self::assertStringContainsString('&lt;script&gt;', $response->structured['summary']);
        self::assertStringNotContainsString('evil.test', $response->structured['nested']['link']);
    }

    public function test_tool_calls_pass_through_untouched(): void
    {
        // Documented limitation: Control C only rewrites text/structured; the model's tool calls
        // are governed by Controls A (firewall) and D (HITL), not sanitized here.
        $response = AgentResponseFactory::make('<script>x</script>');
        $response->toolCalls = collect(['refund' => ['amount' => '<b>10</b>']]);

        $out = (new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, new NullPiiRedaction))->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => $response,
        );

        self::assertSame(['refund' => ['amount' => '<b>10</b>']], $out->toolCalls->all()); // untouched
        self::assertStringContainsString('&lt;script&gt;', $out->text); // text still sanitized
    }

    public function test_disabled_middleware_leaves_response_untouched(): void
    {
        $middleware = new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, new NullPiiRedaction, enabled: false);

        $response = $middleware->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => AgentResponseFactory::make('<b>raw</b>'),
        );

        self::assertSame('<b>raw</b>', $response->text);
    }

    public function test_records_html_and_markdown_and_pii_stats(): void
    {
        $stats = new ArrayOutputStatStore;
        $pii = new class implements PiiRedaction
        {
            public function redact(string $text): string
            {
                return str_replace('john@example.com', '[email]', $text);
            }
        };
        $middleware = new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, $pii, stats: $stats);

        $middleware->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => AgentResponseFactory::make('<b>hi</b> ![x](http://evil.test/leak) john@example.com'),
        );

        $totals = $stats->totals();
        self::assertSame(1, $totals[OutputStatKind::HtmlStripped->value]);
        self::assertSame(1, $totals[OutputStatKind::MarkdownSanitized->value]);
        self::assertSame(1, $totals[OutputStatKind::PiiRedaction->value]);
    }

    public function test_records_nothing_when_output_is_clean(): void
    {
        $stats = new ArrayOutputStatStore;
        $middleware = new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, new NullPiiRedaction, stats: $stats);

        $middleware->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => AgentResponseFactory::make('a perfectly clean sentence'),
        );

        self::assertSame(0, $stats->count());
    }

    public function test_stat_store_failure_does_not_break_sanitization(): void
    {
        // A failing stat store must never abort the sanitization pass (that would leak un-neutralised
        // output). The response is still sanitized; the failure is swallowed (logged).
        $throwingStore = new class implements OutputStatStore
        {
            public function record(OutputStatKind $kind, int $count = 1): void
            {
                throw new \RuntimeException('DB down');
            }

            public function totals(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }
        };
        $middleware = new GuardrailOutputMiddleware(new HtmlMarkdownSanitizer, new NullPiiRedaction, stats: $throwingStore);

        $response = $middleware->handle(
            AgentPromptFactory::make('hi'),
            static fn ($prompt) => AgentResponseFactory::make('<script>x</script>'),
        );

        self::assertStringContainsString('&lt;script&gt;', $response->text);
    }
}
