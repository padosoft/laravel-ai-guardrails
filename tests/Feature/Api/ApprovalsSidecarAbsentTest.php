<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Contracts\HitlRequestStore;
use Padosoft\AiGuardrails\Hitl\DatabaseHitlRequestStore;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;

/**
 * Step 1 (P2): When hitl_requests.store=database but the sidecar migration has NOT run,
 * GET /approvals must return 200 with the pending items (tool='', arguments={}),
 * NOT 500. The forRunIds() call in ApprovalReadModel::enrich() is best-effort and
 * degrades to an empty sidecar map on any Throwable.
 */
final class ApprovalsSidecarAbsentTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LaravelFlowServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.hitl.enabled', true);
        // Bind the database sidecar store — but we intentionally do NOT run its migration.
        $app['config']->set('ai-guardrails.hitl_requests.store', 'database');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('laravel-flow.persistence.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Run the real flow migrations so we have flow_approvals / flow_runs, but intentionally
        // do NOT run the ai_guardrails_hitl_requests migration — that is the scenario under test.
        $dir = __DIR__.'/../../../vendor/padosoft/laravel-flow/database/migrations';
        $files = glob($dir.'/*.php') ?: [];
        self::assertNotEmpty($files, "No laravel-flow migrations found at {$dir}");
        sort($files);
        foreach ($files as $file) {
            (require $file)->up();
        }

        // Bind a DatabaseHitlRequestStore pointing at the (absent) ai_guardrails_hitl_requests table.
        // This simulates a host that configured hitl_requests.store=database but has not yet run the
        // migration — the table does not exist, so forRunIds() will throw "no such table".
        $this->app->instance(
            HitlRequestStore::class,
            new DatabaseHitlRequestStore(null, 'ai_guardrails_hitl_requests'),
        );
    }

    public function test_approvals_list_returns_200_with_pending_items_when_sidecar_table_absent(): void
    {
        // Park a destructive call so there is a pending approval row in flow_approvals.
        $pending = $this->resolve(ApprovalRouter::class)
            ->route('refund', FakeDestructiveTool::class, ['order_id' => 'X1'], 9);
        self::assertNotEmpty($pending->runId);

        // GET /approvals must return 200 (not 500) even though forRunIds() will throw because the
        // sidecar table is absent. Pending items degrade to tool='' and arguments={}.
        $response = $this->getJson('/ai-guardrails/api/approvals')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.approval-list');

        $items = $response->json('data.pending');
        self::assertIsArray($items);
        self::assertNotEmpty($items, 'Expected at least one pending item in the list.');

        $item = $items[0];
        // Core fields must be present from the flow_approvals row.
        self::assertArrayHasKey('approval_id', $item);
        self::assertArrayHasKey('run_id', $item);
        self::assertSame('pending', $item['status']);
        // Sidecar fields degrade gracefully: tool falls back to '' and arguments to {} (object).
        self::assertSame('', $item['tool']);
        self::assertStringContainsString('"arguments":{}', $response->getContent());
    }
}
