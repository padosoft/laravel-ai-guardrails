<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Tests\TestCase;

final class OverviewEndpointTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.audit.store', 'array');
    }

    public function test_overview_returns_the_enveloped_payload(): void
    {
        $this->getJson('/ai-guardrails/api/overview')
            ->assertOk()
            ->assertJsonPath('schema_version', 'ai-guardrails.api.v1')
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.overview')
            ->assertJsonStructure([
                'data' => [
                    'controls' => [['key', 'label', 'enabled']],
                    'totals' => ['attempts_24h', 'blocked_24h'],
                ],
            ]);
    }
}
