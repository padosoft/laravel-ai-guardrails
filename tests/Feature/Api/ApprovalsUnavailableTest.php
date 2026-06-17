<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Hitl\PendingApproval;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Approval surface when HITL is unavailable (hitl disabled / no flow persistence): the list degrades
 * to empty and decisions return 409 rather than 500.
 */
final class ApprovalsUnavailableTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.hitl.enabled', false);
    }

    public function test_pending_list_is_empty_and_does_not_500(): void
    {
        $this->getJson('/ai-guardrails/api/approvals')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.approval-list')
            ->assertJsonPath('data.pending', []);
    }

    public function test_approve_returns_409_when_hitl_unavailable(): void
    {
        $this->postJson('/ai-guardrails/api/approvals/some-token/approve')
            ->assertStatus(409)
            ->assertJsonPath('data.decision', 'unavailable')
            ->assertJsonPath('data.error', 'hitl_unavailable');
    }

    public function test_reject_returns_409_when_hitl_unavailable(): void
    {
        $this->postJson('/ai-guardrails/api/approvals/some-token/reject')
            ->assertStatus(409)
            ->assertJsonPath('data.error', 'hitl_unavailable');
    }

    public function test_logic_exception_from_router_maps_to_409_not_422(): void
    {
        // Simulate a race where isAvailable() returned true but the router throws LogicException on
        // the actual call (e.g. misconfiguration discovered late). Must return 409 hitl_unavailable,
        // not 422 decision_failed.
        $stub = new class implements ApprovalRouter {
            public function isAvailable(): bool { return true; }

            public function route(string $toolName, string $toolClass, array $arguments, int|string|null $principalId): PendingApproval
            {
                throw new \LogicException('stub');
            }

            public function approve(string $token, array $actor = []): void
            {
                throw new \LogicException('Approval routing is unavailable.');
            }

            public function reject(string $token, array $actor = []): void
            {
                throw new \LogicException('Approval routing is unavailable.');
            }
        };

        $this->app->instance(ApprovalRouter::class, $stub);

        $this->postJson('/ai-guardrails/api/approvals/any-token/approve')
            ->assertStatus(409)
            ->assertJsonPath('data.decision', 'unavailable')
            ->assertJsonPath('data.error', 'hitl_unavailable');
    }
}
