<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Tests\TestCase;

final class OverviewApiTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.audit.store', 'array');
    }

    private function seedAttempt(bool $blocked, ?string $ruleId = null): void
    {
        /** @var InjectionAuditStore $store */
        $store = $this->app->make(InjectionAuditStore::class);
        $store->append(new InjectionAttempt(
            prompt: 'test prompt',
            blocked: $blocked,
            ruleId: $ruleId,
            principalId: 'u1',
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ));
    }

    public function test_overview_exposes_observed_totals_posture_and_spark(): void
    {
        config()->set('ai-guardrails.api.enabled', true);
        // seed: 1 blocked (enforce) + 1 observed (rule matched, not blocked) in last 24h
        $this->seedAttempt(blocked: true, ruleId: 'exfiltrate');
        $this->seedAttempt(blocked: false, ruleId: 'role_override'); // observed

        $data = $this->getJson('/ai-guardrails/api/overview')->assertOk()->json('data');

        $this->assertArrayHasKey('observed_24h', $data['totals']);
        $this->assertArrayHasKey('pending_approvals', $data['totals']);
        $this->assertSame(1, $data['totals']['observed_24h']);
        foreach ($data['controls'] as $control) {
            $this->assertArrayHasKey('posture', $control);
            $this->assertArrayHasKey('spark', $control);
            $this->assertCount(12, $control['spark']);
        }
    }

    public function test_overview_keeps_v1_0_fields(): void
    {
        $data = $this->getJson('/ai-guardrails/api/overview')->assertOk()->json('data');

        // top-level envelope fields
        $this->assertArrayHasKey('controls', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('ruleset_version', $data);

        // original totals
        $this->assertArrayHasKey('attempts_24h', $data['totals']);
        $this->assertArrayHasKey('blocked_24h', $data['totals']);
        $this->assertArrayHasKey('sampled', $data['totals']);

        // original control fields
        foreach ($data['controls'] as $control) {
            $this->assertArrayHasKey('key', $control);
            $this->assertArrayHasKey('label', $control);
            $this->assertArrayHasKey('enabled', $control);
            $this->assertArrayHasKey('mode', $control);
            $this->assertIsString($control['key']);
            $this->assertIsString($control['label']);
            $this->assertIsBool($control['enabled']);
            $this->assertIsString($control['mode']);
        }

        $this->assertIsInt($data['totals']['attempts_24h']);
        $this->assertIsInt($data['totals']['blocked_24h']);
        $this->assertIsBool($data['totals']['sampled']);
        $this->assertIsString($data['ruleset_version']);
    }

    public function test_observed_24h_is_zero_and_spark_all_zeros_when_no_attempts(): void
    {
        $data = $this->getJson('/ai-guardrails/api/overview')->assertOk()->json('data');

        $this->assertSame(0, $data['totals']['observed_24h']);

        foreach ($data['controls'] as $control) {
            $this->assertCount(12, $control['spark']);
            foreach ($control['spark'] as $bucket) {
                $this->assertSame(0, $bucket);
            }
        }
    }

    public function test_posture_reflects_control_mode(): void
    {
        $this->app['config']->set('ai-guardrails.modes.input_screen', 'enforce');
        $this->app['config']->set('ai-guardrails.modes.tool_firewall', 'monitor');
        $this->app['config']->set('ai-guardrails.output_handler.enabled', false);

        $data = $this->getJson('/ai-guardrails/api/overview')->assertOk()->json('data');
        $controls = collect($data['controls'])->keyBy('key');

        $this->assertSame('Engaged', $controls['input_screen']['posture']);
        $this->assertSame('Observing', $controls['tool_firewall']['posture']);
        $this->assertSame('Disabled', $controls['output_handler']['posture']);
    }
}
