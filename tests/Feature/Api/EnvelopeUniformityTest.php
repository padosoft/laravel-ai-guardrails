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
 * Covers all 7 200-OK read endpoints plus the audit.show 404 path (envelope applies on all statuses).
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

    /** @return list<array{0:string}> */
    public static function readEndpoints(): array
    {
        return [
            ['/ai-guardrails/api/overview'],
            ['/ai-guardrails/api/audit'],
            ['/ai-guardrails/api/audit/trend'],
            ['/ai-guardrails/api/firewall'],
            ['/ai-guardrails/api/output/stats'],
            ['/ai-guardrails/api/approvals'],
            ['/ai-guardrails/api/settings'],
        ];
    }

    #[DataProvider('readEndpoints')]
    public function test_every_read_endpoint_uses_the_same_envelope(string $url): void
    {
        $response = $this->getJson($url)->assertOk();

        $body = $response->json();
        self::assertIsArray($body);
        self::assertArrayHasKey('schema_version', $body);
        self::assertArrayHasKey('schema', $body);
        self::assertArrayHasKey('data', $body);

        self::assertSame(ApiSchema::VERSION, $body['schema_version']);
        self::assertIsString($body['schema']);
        self::assertStringStartsWith(ApiSchema::VERSION.'.', $body['schema']);
    }

    /**
     * `audit.show` always returns 404 for a non-existent id, but the envelope is still applied.
     * Verified separately because the data-provider above uses assertOk() for the 200 paths.
     */
    public function test_audit_show_404_uses_the_same_envelope(): void
    {
        $response = $this->getJson('/ai-guardrails/api/audit/999999')->assertStatus(404);

        $body = $response->json();
        self::assertIsArray($body);
        self::assertArrayHasKey('schema_version', $body);
        self::assertArrayHasKey('schema', $body);
        self::assertArrayHasKey('data', $body);

        self::assertSame(ApiSchema::VERSION, $body['schema_version']);
        self::assertIsString($body['schema']);
        self::assertStringStartsWith(ApiSchema::VERSION.'.', $body['schema']);
    }
}
