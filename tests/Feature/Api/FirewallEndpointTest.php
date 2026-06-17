<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\FirewallRejection;
use Padosoft\AiGuardrails\Tests\TestCase;

final class FirewallEndpointTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.firewall_log.store', 'array');
    }

    private function seedRejections(): FirewallRejectionStore
    {
        $store = $this->app->make(FirewallRejectionStore::class);
        $utc = new DateTimeZone('UTC');
        $store->record(new FirewallRejection('refund order tool', 'u1', ['user_id' => 'forbidden'], new DateTimeImmutable('2026-01-01 10:00:00', $utc)));
        $store->record(new FirewallRejection('send email tool', 'u2', ['evil' => 'unknown argument'], new DateTimeImmutable('2026-01-02 10:00:00', $utc)));

        return $store;
    }

    public function test_index_returns_enveloped_list_newest_first(): void
    {
        $this->seedRejections();

        $this->getJson('/ai-guardrails/api/firewall')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.firewall')
            ->assertJsonPath('data.entries.0.tool', 'send email tool')
            ->assertJsonPath('data.entries.0.violation_count', 1)
            ->assertJsonPath('data.entries.1.tool', 'refund order tool')
            ->assertJsonPath('data.next_cursor', null)
            ->assertJsonStructure([
                'data' => ['entries' => [['id', 'tool', 'principal_id', 'violations', 'violation_count', 'occurred_at']], 'next_cursor'],
            ]);
    }

    public function test_index_filters_by_principal(): void
    {
        $this->seedRejections();

        $response = $this->getJson('/ai-guardrails/api/firewall?principal_id=u2')->assertOk();

        $entries = $response->json('data.entries');
        self::assertCount(1, $entries);
        self::assertSame('send email tool', $entries[0]['tool']);
    }

    public function test_index_search_filters_by_tool(): void
    {
        $this->seedRejections();

        $response = $this->getJson('/ai-guardrails/api/firewall?q=refund')->assertOk();

        $entries = $response->json('data.entries');
        self::assertCount(1, $entries);
        self::assertSame('refund order tool', $entries[0]['tool']);
    }

    public function test_array_query_params_do_not_500(): void
    {
        $this->seedRejections();

        $response = $this->getJson('/ai-guardrails/api/firewall?principal_id[]=u1&limit[]=10&cursor[]=1')->assertOk();

        self::assertCount(2, $response->json('data.entries'));
    }
}
