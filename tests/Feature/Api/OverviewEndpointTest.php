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
                    'controls' => [['key', 'label', 'enabled', 'mode']],
                    'totals' => ['attempts_24h', 'blocked_24h', 'sampled'],
                    'ruleset_version',
                ],
            ]);
    }

    public function test_overview_surfaces_each_control_mode(): void
    {
        $this->app['config']->set('ai-guardrails.modes.input_screen', 'monitor');
        $this->app['config']->set('ai-guardrails.modes.tool_firewall', 'enforce');
        $this->app['config']->set('ai-guardrails.output_handler.enabled', false); // → off

        $data = $this->getJson('/ai-guardrails/api/overview')->assertOk()->json('data');
        $modes = collect($data['controls'])->pluck('mode', 'key');

        self::assertSame('monitor', $modes['input_screen']);
        self::assertSame('enforce', $modes['tool_firewall']);
        self::assertSame('off', $modes['output_handler']); // disabled → off, regardless of modes.*
    }

    public function test_overview_reports_the_active_ruleset_version(): void
    {
        $this->app['config']->set('ai-guardrails.pattern_safety.ruleset_version', 'v7');

        $this->getJson('/ai-guardrails/api/overview')
            ->assertOk()
            ->assertJsonPath('data.ruleset_version', 'v7');
    }
}
