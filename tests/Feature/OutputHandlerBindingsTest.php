<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;
use Padosoft\AiGuardrails\Contracts\PiiRedaction;
use Padosoft\AiGuardrails\Output\ArrayOutputStatStore;
use Padosoft\AiGuardrails\Output\GuardrailOutputMiddleware;
use Padosoft\AiGuardrails\Output\HtmlMarkdownSanitizer;
use Padosoft\AiGuardrails\Output\NullOutputStatStore;
use Padosoft\AiGuardrails\Output\NullPiiRedaction;
use Padosoft\AiGuardrails\Output\PassthroughSanitizer;
use Padosoft\AiGuardrails\Output\RealPiiRedaction;
use Padosoft\AiGuardrails\Tests\TestCase;

final class OutputHandlerBindingsTest extends TestCase
{
    private function reregister(): void
    {
        $this->app->forgetInstance(OutputSanitizer::class);
        $this->app->forgetInstance(PiiRedaction::class);
        $this->app->forgetInstance(GuardrailOutputMiddleware::class);
        (new AiGuardrailsServiceProvider($this->app))->register();
    }

    public function test_sanitizer_and_middleware_resolve_when_output_enabled(): void
    {
        self::assertInstanceOf(HtmlMarkdownSanitizer::class, $this->resolve(OutputSanitizer::class));
        self::assertInstanceOf(GuardrailOutputMiddleware::class, $this->resolve(GuardrailOutputMiddleware::class));
    }

    public function test_real_pii_redaction_bound_when_enabled_and_package_present(): void
    {
        // Default config: redact_pii = true, and pii-redactor is installed (require-dev).
        $pii = $this->resolve(PiiRedaction::class);

        self::assertInstanceOf(RealPiiRedaction::class, $pii);
        self::assertIsString($pii->redact('contact john@example.com')); // composes without error
    }

    public function test_null_pii_redaction_when_disabled(): void
    {
        $this->app['config']->set('ai-guardrails.output_handler.redact_pii', false);
        $this->reregister();

        self::assertInstanceOf(NullPiiRedaction::class, $this->resolve(PiiRedaction::class));
    }

    public function test_master_kill_switch_off_degrades_output_handler(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->reregister();

        self::assertInstanceOf(PassthroughSanitizer::class, $this->resolve(OutputSanitizer::class));
        self::assertInstanceOf(NullPiiRedaction::class, $this->resolve(PiiRedaction::class));
    }

    public function test_output_handler_disabled_degrades_sanitizer(): void
    {
        $this->app['config']->set('ai-guardrails.output_handler.enabled', false);
        $this->reregister();

        self::assertInstanceOf(PassthroughSanitizer::class, $this->resolve(OutputSanitizer::class));
    }

    public function test_output_stat_store_resolves_per_config(): void
    {
        // Default: null store (no persistence).
        self::assertInstanceOf(NullOutputStatStore::class, $this->resolve(OutputStatStore::class));

        $this->app['config']->set('ai-guardrails.output_stats.store', 'array');
        $this->app->forgetInstance(OutputStatStore::class);
        self::assertInstanceOf(ArrayOutputStatStore::class, $this->resolve(OutputStatStore::class));
    }

    public function test_output_stat_store_is_null_when_master_off(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.output_stats.store', 'array');
        $this->app->forgetInstance(OutputStatStore::class);

        self::assertInstanceOf(NullOutputStatStore::class, $this->resolve(OutputStatStore::class));
    }
}
