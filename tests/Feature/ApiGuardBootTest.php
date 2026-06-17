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
        $this->app['config']->set('ai-guardrails.api.enabled', true);
        $this->app['config']->set('ai-guardrails.api.middleware', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/api\.middleware has no \(string\) middleware/');

        (new AiGuardrailsServiceProvider($this->app))->boot();
    }

    /**
     * Fail CLOSED: a partial package-config merge can leave api.middleware as null
     * (nested defaults are not restored), which must still be treated as "open".
     */
    public function test_boot_throws_when_api_enabled_with_null_middleware(): void
    {
        $this->app['config']->set('ai-guardrails.api.enabled', true);
        $this->app['config']->set('ai-guardrails.api.middleware', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no \(string\) middleware/');

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

    public function test_boot_throws_when_api_enabled_with_non_string_middleware(): void
    {
        // A non-empty array of non-strings filters to empty → would otherwise register an open API.
        $this->app['config']->set('ai-guardrails.api.enabled', true);
        $this->app['config']->set('ai-guardrails.api.middleware', [123, ['x']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no \(string\) middleware/');

        (new AiGuardrailsServiceProvider($this->app))->boot();
    }
}
