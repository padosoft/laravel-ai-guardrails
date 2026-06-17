<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

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
