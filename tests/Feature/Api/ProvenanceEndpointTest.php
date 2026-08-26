<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Contracts\GatedToolCallStore;
use Padosoft\AiGuardrails\Provenance\GatedToolCall;
use Padosoft\AiGuardrails\Tests\TestCase;

final class ProvenanceEndpointTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.provenance_log.store', 'array');
    }

    private function seedGatedCalls(): GatedToolCallStore
    {
        $store = $this->app->make(GatedToolCallStore::class);
        $utc = new DateTimeZone('UTC');
        $store->record(new GatedToolCall('refund', 'u1', ['untrusted_external'], true, new DateTimeImmutable('2026-01-01 10:00:00', $utc)));
        $store->record(new GatedToolCall('send_email', 'u2', ['untrusted_external'], false, new DateTimeImmutable('2026-01-02 10:00:00', $utc)));

        return $store;
    }

    public function test_index_returns_enveloped_list_newest_first(): void
    {
        $this->seedGatedCalls();

        $this->getJson('/ai-guardrails/api/provenance')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.provenance')
            ->assertJsonPath('data.entries.0.tool', 'send_email')
            ->assertJsonPath('data.entries.0.blocked', false)
            ->assertJsonPath('data.entries.1.tool', 'refund')
            ->assertJsonPath('data.entries.1.blocked', true)
            ->assertJsonPath('data.next_cursor', null)
            ->assertJsonStructure([
                'data' => ['entries' => [['id', 'tool', 'principal_id', 'tiers', 'blocked', 'occurred_at']], 'next_cursor'],
            ]);
    }

    public function test_blocked_filter_is_the_monitor_rollout_view(): void
    {
        // `blocked=0` is the question a monitor rollout is FOR: what ran
        // that enforcement would have refused?
        $this->seedGatedCalls();

        $observed = $this->getJson('/ai-guardrails/api/provenance?blocked=0')->assertOk()->json('data.entries');
        self::assertCount(1, $observed);
        self::assertSame('send_email', $observed[0]['tool']);

        $blocked = $this->getJson('/ai-guardrails/api/provenance?blocked=1')->assertOk()->json('data.entries');
        self::assertCount(1, $blocked);
        self::assertSame('refund', $blocked[0]['tool']);
    }

    public function test_an_unparseable_blocked_param_returns_everything(): void
    {
        // Not false. Defaulting to false would hide the refused rows an
        // operator opened this page to see.
        $this->seedGatedCalls();

        self::assertCount(2, $this->getJson('/ai-guardrails/api/provenance?blocked=maybe')->assertOk()->json('data.entries'));
    }

    public function test_index_filters_by_principal_and_tool(): void
    {
        $this->seedGatedCalls();

        self::assertCount(1, $this->getJson('/ai-guardrails/api/provenance?principal_id=u2')->assertOk()->json('data.entries'));
        self::assertCount(1, $this->getJson('/ai-guardrails/api/provenance?q=refund')->assertOk()->json('data.entries'));
    }

    public function test_array_query_params_do_not_500(): void
    {
        $this->seedGatedCalls();

        $response = $this->getJson('/ai-guardrails/api/provenance?principal_id[]=u1&limit[]=10&cursor[]=1&blocked[]=1')->assertOk();

        self::assertCount(2, $response->json('data.entries'));
    }

    public function test_zero_like_cursor_is_ignored_not_treated_as_empty_page(): void
    {
        $this->seedGatedCalls();

        foreach (['0', '00'] as $bad) {
            $response = $this->getJson('/ai-guardrails/api/provenance?cursor='.$bad)->assertOk();
            self::assertCount(2, $response->json('data.entries'), "cursor=$bad should be ignored");
        }
    }

    public function test_the_overview_reports_control_p_as_disabled_by_default(): void
    {
        $controls = $this->getJson('/ai-guardrails/api/overview')->assertOk()->json('data.controls');
        $provenance = collect($controls)->firstWhere('key', 'provenance');

        self::assertNotNull($provenance, 'Control P must appear in the overview alongside A–D.');
        self::assertFalse($provenance['enabled']);
        // The absent `enabled` key must not read as enforce the way it would
        // for the three controls that default ON.
        self::assertSame('off', $provenance['mode']);
        self::assertSame('Disabled', $provenance['posture']);
    }

    public function test_the_overview_reports_the_posture_once_enabled(): void
    {
        config()->set('ai-guardrails.provenance.enabled', true);
        config()->set('ai-guardrails.modes.provenance', 'monitor');

        $controls = $this->getJson('/ai-guardrails/api/overview')->assertOk()->json('data.controls');
        $provenance = collect($controls)->firstWhere('key', 'provenance');

        self::assertTrue($provenance['enabled']);
        self::assertSame('monitor', $provenance['mode']);
        self::assertSame('Observing', $provenance['posture']);
    }
}
