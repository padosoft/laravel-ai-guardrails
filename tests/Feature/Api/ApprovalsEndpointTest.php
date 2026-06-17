<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Hitl\ToolApprovalHandler;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\TestCase;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;

/**
 * End-to-end Control D approval surface against the real padosoft/laravel-flow (persistence on).
 */
final class ApprovalsEndpointTest extends TestCase
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('laravel-flow.persistence.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Run the real flow migrations on the in-memory connection (loadMigrationsFrom is not wired
        // in this package's test harness; the DB store tests migrate manually too).
        $dir = __DIR__.'/../../../vendor/padosoft/laravel-flow/database/migrations';
        $files = glob($dir.'/*.php') ?: [];
        // Fail fast if the dependency layout moved — otherwise migrations silently skip and the real
        // failure surfaces later as a confusing "no such table" error.
        self::assertNotEmpty($files, "No laravel-flow migrations found at {$dir}");
        // glob() order isn't guaranteed across filesystems; the later flow migrations add columns to
        // tables the earlier ones create, so apply them in deterministic (timestamp-prefixed) order.
        sort($files);
        foreach ($files as $file) {
            (require $file)->up();
        }
    }

    private function park(): string
    {
        // Park a destructive call → flow persists a pending approval and issues a token.
        $pending = $this->resolve(ApprovalRouter::class)
            ->route('refund', FakeDestructiveTool::class, ['order_id' => 'A1'], 9);

        return $pending->token;
    }

    public function test_pending_appears_in_list_then_approve_resolves_it(): void
    {
        $token = $this->park();

        $list = $this->getJson('/ai-guardrails/api/approvals')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.approval-list')
            ->assertJsonStructure(['data' => ['pending' => [['approval_id', 'run_id', 'step_name', 'status', 'expires_at', 'created_at']]]]);

        $pending = $list->json('data.pending');
        self::assertCount(1, $pending);
        self::assertSame('pending', $pending[0]['status']);
        self::assertNotEmpty($pending[0]['run_id']);

        $this->postJson('/ai-guardrails/api/approvals/'.$token.'/approve', ['actor' => ['note' => 'looks ok']])
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.approval-decision')
            ->assertJsonPath('data.decision', 'approved');

        // Once approved it is no longer pending.
        $this->getJson('/ai-guardrails/api/approvals')
            ->assertOk()
            ->assertJsonPath('data.pending', []);
    }

    public function test_approve_works_in_a_fresh_router_that_never_called_route(): void
    {
        $token = $this->park();

        // Simulate a separate request/worker: drop the router instance so the decision is handled by
        // a brand-new FlowApprovalRouter that never ran route(). It must still register the flow
        // definition before Flow::resume, otherwise the valid token would fail to resolve.
        $this->app->forgetInstance(ApprovalRouter::class);

        $this->postJson('/ai-guardrails/api/approvals/'.$token.'/approve')
            ->assertOk()
            ->assertJsonPath('data.decision', 'approved');
    }

    public function test_reject_resolves_a_pending_approval(): void
    {
        $token = $this->park();

        $this->postJson('/ai-guardrails/api/approvals/'.$token.'/reject')
            ->assertOk()
            ->assertJsonPath('data.decision', 'rejected');

        $this->getJson('/ai-guardrails/api/approvals')
            ->assertOk()
            ->assertJsonPath('data.pending', []);
    }

    public function test_invalid_token_fails_cleanly_with_422(): void
    {
        $this->park(); // ensure the flow is registered

        $this->postJson('/ai-guardrails/api/approvals/not-a-real-token/approve')
            ->assertStatus(422)
            ->assertJsonPath('data.error', 'decision_failed');
    }

    public function test_list_excludes_non_guardrails_flow_approvals(): void
    {
        $this->park(); // a guardrails pending approval

        // A foreign (non-guardrails) flow with its own approval gate creates a pending row in the
        // SHARED flow_approvals table — it must NOT appear in the ai-guardrails list. (Execution
        // pauses at the gate, so the step handler is never actually run.)
        Flow::define('other-flow')
            ->withInput(['x'])
            ->approvalGate('gate')
            ->step('noop', ToolApprovalHandler::class)
            ->register();
        Flow::execute('other-flow', ['x' => 1]);

        $pending = $this->getJson('/ai-guardrails/api/approvals')->assertOk()->json('data.pending');

        self::assertCount(1, $pending); // only the guardrails approval, not the foreign one
    }

    public function test_list_is_empty_when_hitl_disabled_even_with_a_pending_row(): void
    {
        $this->park(); // a real pending row now exists in flow_approvals

        // Disable HITL and rebind the router → NullApprovalRouter (unavailable).
        config(['ai-guardrails.hitl.enabled' => false]);
        $this->app->forgetInstance(ApprovalRouter::class);

        // The queue must not leak the pending row when HITL is disabled.
        $this->getJson('/ai-guardrails/api/approvals')
            ->assertOk()
            ->assertJsonPath('data.pending', []);
    }
}
