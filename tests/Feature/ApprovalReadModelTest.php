<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Hitl\ApprovalReadModel;
use Padosoft\AiGuardrails\Hitl\ArrayHitlRequestStore;
use Padosoft\AiGuardrails\Tests\TestCase;

final class ApprovalReadModelTest extends TestCase
{
    public function test_pending_degrades_to_empty_when_flow_tables_are_absent(): void
    {
        // padosoft/laravel-flow is installed (require-dev) so FlowApprovalRecord exists, but no flow
        // migrations have run here — querying flow_approvals/flow_runs would throw. The read model
        // must catch that and return [] rather than 500 the read-only endpoint.
        self::assertSame([], (new ApprovalReadModel)->pending());
    }

    public function test_pending_count_degrades_to_zero_when_flow_tables_are_absent(): void
    {
        // Same degradation contract as pending() but for the count path — must return 0 (int)
        // rather than throwing when the flow tables are not migrated.
        self::assertSame(0, (new ApprovalReadModel)->pendingCount());
    }

    public function test_relative_time_is_english_even_when_host_app_locale_is_non_english(): void
    {
        // Prove the locale('en') pin: even when the host app is set to Italian, the
        // requested_ago and expires_in strings are still produced in English.
        $this->app->setLocale('it');

        $sidecar = new ArrayHitlRequestStore;
        $readModel = new ApprovalReadModel($sidecar);

        $createdAt = new DateTimeImmutable('2 minutes ago', new DateTimeZone('UTC'));
        $expiresAt = new DateTimeImmutable('+28 minutes', new DateTimeZone('UTC'));

        $pending = $readModel->pendingWithStub([
            [
                'approval_id' => 'appr-locale-test',
                'run_id' => 'run-locale-test',
                'step_name' => 'approve',
                'status' => 'pending',
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'created_at' => $createdAt->format(DATE_ATOM),
            ],
        ]);

        self::assertCount(1, $pending);

        // With Italian locale, Carbon would normally produce "2 minuti fa" (not "ago").
        // The locale('en') pin must override that — still ends with "ago".
        self::assertStringEndsWith('ago', $pending[0]['requested_ago']);

        // expires_in must also be English: "in X minutes" / "X minutes from now"
        $expiresIn = $pending[0]['expires_in'];
        self::assertIsString($expiresIn);
        self::assertTrue(
            str_starts_with($expiresIn, 'in ') || str_ends_with($expiresIn, 'from now'),
            "expires_in must be English-pinned, got: '{$expiresIn}'"
        );
    }
}
