<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Hitl\ApprovalReadModel;
use Padosoft\AiGuardrails\Hitl\ArrayHitlRequestStore;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Task 4 — GET /approvals enriched with tool, arguments, requested_ago, expires_in.
 * Uses a stub router (no laravel-flow dependency) so the sidecar is the ONLY source of truth.
 */
final class ApprovalsWithSidecarTest extends TestCase
{
    private ArrayHitlRequestStore $sidecar;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.hitl.enabled', false); // controller checks isAvailable()
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sidecar = new ArrayHitlRequestStore;
    }

    /** Build an ApprovalReadModel injected with the sidecar. */
    private function readModel(): ApprovalReadModel
    {
        return new ApprovalReadModel($this->sidecar);
    }

    // ── Test: sidecar row exists → tool + arguments returned ────────────────

    public function test_pending_item_has_tool_arguments_requested_ago_and_expires_in(): void
    {
        // Record a sidecar row
        $runId = 'run-abc-123';
        $approvalId = 'appr-1';
        $this->sidecar->record($runId, $approvalId, 'refund', ['order_id' => 'X1'], 9);

        // Seed the read model with a matching fake pending row
        $createdAt = new DateTimeImmutable('2 minutes ago', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('+28 minutes', new DateTimeZone('UTC'));

        $pending = $this->readModel()->pendingWithStub([
            [
                'approval_id' => $approvalId,
                'run_id' => $runId,
                'step_name' => 'approve',
                'status' => 'pending',
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'created_at' => $createdAt->format(DATE_ATOM),
            ],
        ]);

        self::assertCount(1, $pending);
        self::assertSame('refund', $pending[0]['tool']);
        self::assertSame(['order_id' => 'X1'], $pending[0]['arguments']);
        self::assertArrayHasKey('requested_ago', $pending[0]);
        self::assertNotEmpty($pending[0]['requested_ago']);
        self::assertArrayHasKey('expires_in', $pending[0]);
        // Existing fields still present
        self::assertSame($approvalId, $pending[0]['approval_id']);
        self::assertSame($runId, $pending[0]['run_id']);
    }

    public function test_missing_sidecar_row_returns_empty_tool_and_arguments_gracefully(): void
    {
        // No sidecar record for this run_id
        $runId = 'run-no-sidecar';
        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $pending = $this->readModel()->pendingWithStub([
            [
                'approval_id' => 'appr-2',
                'run_id' => $runId,
                'step_name' => 'approve',
                'status' => 'pending',
                'expires_at' => null,
                'created_at' => $createdAt->format(DATE_ATOM),
            ],
        ]);

        self::assertCount(1, $pending);
        self::assertSame('', $pending[0]['tool']);
        self::assertSame([], $pending[0]['arguments']);
        self::assertNull($pending[0]['expires_in']); // expires_at was null
    }

    public function test_hitl_disabled_list_returns_empty_from_api(): void
    {
        // hitl.enabled = false → router unavailable → pending = []
        $this->getJson('/ai-guardrails/api/approvals')
            ->assertOk()
            ->assertJsonPath('data.pending', []);
    }
}
