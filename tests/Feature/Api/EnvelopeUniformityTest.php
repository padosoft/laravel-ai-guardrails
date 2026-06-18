<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Http\ApiSchema;
use Padosoft\AiGuardrails\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Proves the `{schema_version, schema, data}` envelope is applied UNIFORMLY across every read
 * endpoint: same schema_version, a per-endpoint schema discriminator under that namespace, and data.
 * Covers all 8 200-OK read endpoints plus the audit.show 404 path (envelope applies on all statuses).
 */
final class EnvelopeUniformityTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        // Pin the prefix so the hard-coded URLs below don't 404 under a non-default AI_GUARDRAILS_API_PREFIX.
        $app['config']->set('ai-guardrails.api.prefix', 'ai-guardrails/api');
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        // In-memory / config stores so nothing 500s and no DB is required; hitl off → approvals empty.
        $app['config']->set('ai-guardrails.audit.store', 'array');
        $app['config']->set('ai-guardrails.firewall_log.store', 'array');
        $app['config']->set('ai-guardrails.output_stats.store', 'array');
        $app['config']->set('ai-guardrails.settings.store', 'config');
        $app['config']->set('ai-guardrails.hitl.enabled', false);
    }

    /** @return list<array{0:string,1:string}> url => its exact expected schema discriminator. */
    public static function readEndpoints(): array
    {
        return [
            ['/ai-guardrails/api/overview', ApiSchema::SCHEMA_OVERVIEW],
            ['/ai-guardrails/api/audit', ApiSchema::SCHEMA_AUDIT_LIST],
            ['/ai-guardrails/api/audit/trend', ApiSchema::SCHEMA_AUDIT_TREND],
            ['/ai-guardrails/api/firewall', ApiSchema::SCHEMA_FIREWALL],
            ['/ai-guardrails/api/output/stats', ApiSchema::SCHEMA_OUTPUT_STATS],
            ['/ai-guardrails/api/approvals', ApiSchema::SCHEMA_APPROVAL_LIST],
            ['/ai-guardrails/api/settings', ApiSchema::SCHEMA_SETTINGS],
            ['/ai-guardrails/api/settings/changes', ApiSchema::SCHEMA_SETTINGS_CHANGES],
        ];
    }

    #[DataProvider('readEndpoints')]
    public function test_every_read_endpoint_uses_the_envelope_with_its_own_schema(string $url, string $expectedSchema): void
    {
        $this->assertEnveloped($this->getJson($url)->assertOk()->json(), $expectedSchema);
    }

    /**
     * `audit.show` always returns 404 for a non-existent id, but the envelope is still applied with
     * the detail discriminator. Verified separately because the data-provider above uses assertOk().
     */
    public function test_audit_show_404_uses_the_same_envelope(): void
    {
        $body = $this->getJson('/ai-guardrails/api/audit/999999')->assertStatus(404)->json();

        $this->assertEnveloped($body, ApiSchema::SCHEMA_AUDIT_DETAIL);
    }

    /** Assert the full envelope shape and that `schema` is EXACTLY the per-endpoint discriminator. */
    private function assertEnveloped(mixed $body, string $expectedSchema): void
    {
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertSame(ApiSchema::VERSION, $body['schema_version'] ?? null);
        // Exact, not just the prefix — distinct discriminators are part of the public contract.
        self::assertSame($expectedSchema, $body['schema'] ?? null);
        self::assertStringStartsWith(ApiSchema::VERSION.'.', $expectedSchema);
    }
}
