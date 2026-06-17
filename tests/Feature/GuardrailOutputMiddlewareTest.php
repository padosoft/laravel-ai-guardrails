<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Output\GuardrailOutputMiddleware;
use Padosoft\AiGuardrails\Output\HtmlMarkdownSanitizer;
use Padosoft\AiGuardrails\Output\NullPiiRedaction;
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
}
