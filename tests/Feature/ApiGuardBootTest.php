<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Tests\TestCase;
use RuntimeException;

final class ApiGuardBootTest extends TestCase
{
    public function test_boot_throws_when_api_enabled_with_empty_middleware(): void
    {
        // App boots normally (api.enabled=false by default).
        // Now enable the API surface with no middleware and re-run boot on a fresh provider.
        $this->app['config']->set('ai-guardrails.api.enabled', true);
        $this->app['config']->set('ai-guardrails.api.middleware', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/api\.middleware is empty/');

        (new AiGuardrailsServiceProvider($this->app))->boot();
    }

    public function test_boot_is_fine_when_api_enabled_with_middleware_set(): void
    {
        $this->app['config']->set('ai-guardrails.api.enabled', true);
        $this->app['config']->set('ai-guardrails.api.middleware', ['auth:sanctum']);

        // Should not throw.
        (new AiGuardrailsServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1);
    }
}
