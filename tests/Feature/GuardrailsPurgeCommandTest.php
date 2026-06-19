<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Hitl\DatabaseHitlRequestStore;
use Padosoft\AiGuardrails\Tests\TestCase;

final class GuardrailsPurgeCommandTest extends TestCase
{
    private const TABLE = 'ai_guardrails_injection_audit';

    private const HITL_TABLE = 'ai_guardrails_hitl_requests';

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai-guardrails.audit.store', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_injection_audit_table.php.stub';
        $migration->up();

        // An OLD row (2020) and a RECENT row (now) — strategies act only on rows past the cutoff.
        $store = new DatabaseInjectionAuditStore(null, self::TABLE);
        $store->append(new InjectionAttempt('old secret', true, 'r', '7', new DateTimeImmutable('2020-01-01 10:00:00'), 'v1'));
        $store->append(new InjectionAttempt('recent prompt', false, null, '9', new DateTimeImmutable('now'), 'v1'));
    }

    private function rows(): array
    {
        return DB::table(self::TABLE)->orderBy('id')->get()->all();
    }

    private function hitlRows(): array
    {
        return DB::table(self::HITL_TABLE)->orderBy('id')->get()->all();
    }

    /** Create the hitl_requests table and seed one old + one recent row. */
    private function setUpHitlSidecar(): void
    {
        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_hitl_requests_table.php.stub';
        $migration->up();

        $this->app['config']->set('ai-guardrails.hitl_requests.store', 'database');
        $this->app['config']->set('ai-guardrails.hitl_requests.table', self::HITL_TABLE);

        $store = new DatabaseHitlRequestStore(null, self::HITL_TABLE);
        // Old row (2020) with PII in arguments and principal_id
        DB::table(self::HITL_TABLE)->insert([
            'run_id' => 'run-old',
            'tool' => 'refund',
            'arguments' => json_encode(['order_id' => 'A1', 'amount' => 100]),
            'principal_id' => 'u-old',
            'occurred_at' => '2020-01-01 10:00:00',
        ]);
        // Recent row (should not be touched)
        $store->record('run-recent', 'send_email', ['to' => 'bob@example.com'], 99);
    }

    // ── Existing audit table tests ──────────────────────────────────────────────

    public function test_keep_strategy_is_a_no_op(): void
    {
        $this->artisan('ai-guardrails:purge', ['--strategy' => 'keep'])->assertSuccessful();

        self::assertCount(2, $this->rows());
    }

    public function test_purge_deletes_rows_older_than_the_cutoff(): void
    {
        Log::spy();

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--actor' => 'alice'])
            ->assertSuccessful();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('recent prompt', $rows[0]->prompt); // only the old row was deleted

        // Pre-audit entry must be written (intent recorded BEFORE the mutation).
        Log::shouldHaveReceived('info')->withArgs(static fn (string $m, array $c): bool => str_contains($m, 'retention maintenance starting')
            && $c['actor'] === 'alice')->once();

        // Post-audit entry records the affected-row count.
        Log::shouldHaveReceived('info')->withArgs(static fn (string $m, array $c): bool => str_contains($m, 'retention maintenance applied')
            && $c['actor'] === 'alice'
            && $c['strategy'] === 'purge'
            && $c['affected'] === 1)->once();
    }

    public function test_anonymize_nulls_personal_data_but_keeps_the_row(): void
    {
        $this->artisan('ai-guardrails:purge', ['--strategy' => 'anonymize', '--days' => '30', '--actor' => 'bob'])
            ->assertSuccessful();

        $rows = $this->rows();
        self::assertCount(2, $rows); // nothing deleted

        $old = collect($rows)->firstWhere('prompt', '[anonymized]');
        self::assertNotNull($old);
        self::assertNull($old->principal_id);
        // The recent row is untouched.
        self::assertNotNull(collect($rows)->firstWhere('prompt', 'recent prompt'));
    }

    public function test_dry_run_reports_without_modifying(): void
    {
        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--dry-run' => true])
            ->assertSuccessful();

        self::assertCount(2, $this->rows()); // unchanged
    }

    public function test_mutating_run_requires_an_actor(): void
    {
        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30'])
            ->assertFailed();

        self::assertCount(2, $this->rows()); // nothing erased without an actor
    }

    public function test_invalid_strategy_fails(): void
    {
        $this->artisan('ai-guardrails:purge', ['--strategy' => 'bogus', '--actor' => 'x'])->assertFailed();

        self::assertCount(2, $this->rows());
    }

    public function test_days_zero_is_rejected_for_a_mutating_run(): void
    {
        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '0', '--actor' => 'x'])
            ->assertFailed();

        // No rows touched — the guard fires before any query.
        self::assertCount(2, $this->rows());
    }

    public function test_days_zero_is_allowed_for_dry_run(): void
    {
        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '0', '--dry-run' => true])
            ->assertSuccessful();

        self::assertCount(2, $this->rows()); // dry-run never mutates
    }

    // ── HITL sidecar purge tests (Task 6 — GDPR gap closure) ───────────────────

    /**
     * purge strategy: hard-deletes sidecar rows older than the cutoff.
     * The recent row must survive; the 2020 row must be gone.
     */
    public function test_sidecar_purge_deletes_old_rows(): void
    {
        $this->setUpHitlSidecar();

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--actor' => 'ops'])
            ->assertSuccessful();

        $rows = $this->hitlRows();
        self::assertCount(1, $rows, 'Only the recent row should survive');
        self::assertSame('run-recent', $rows[0]->run_id);
    }

    /**
     * anonymize strategy: redacts arguments to {} and nulls principal_id on old rows.
     * The tool name, run_id, and occurred_at must be preserved (audit trail).
     */
    public function test_sidecar_anonymize_redacts_arguments_and_principal_but_keeps_row(): void
    {
        $this->setUpHitlSidecar();

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'anonymize', '--days' => '30', '--actor' => 'ops'])
            ->assertSuccessful();

        $rows = $this->hitlRows();
        self::assertCount(2, $rows, 'Both rows must survive under anonymize');

        $old = collect($rows)->firstWhere('run_id', 'run-old');
        self::assertNotNull($old);
        self::assertSame('{}', $old->arguments, 'arguments must be redacted to {}');
        self::assertNull($old->principal_id, 'principal_id must be nulled');
        // Audit trail fields preserved
        self::assertSame('refund', $old->tool);
        self::assertSame('run-old', $old->run_id);
        self::assertNotNull($old->occurred_at);

        // Recent row untouched
        $recent = collect($rows)->firstWhere('run_id', 'run-recent');
        self::assertNotNull($recent);
        $recentArgs = json_decode($recent->arguments, true);
        self::assertSame('bob@example.com', $recentArgs['to']);
        self::assertNotNull($recent->principal_id);
    }

    /**
     * keep strategy: no-op — no sidecar rows are modified.
     */
    public function test_sidecar_keep_strategy_is_a_no_op(): void
    {
        $this->setUpHitlSidecar();

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'keep'])->assertSuccessful();

        $rows = $this->hitlRows();
        self::assertCount(2, $rows);
        $old = collect($rows)->firstWhere('run_id', 'run-old');
        // arguments unchanged
        $args = json_decode($old->arguments, true);
        self::assertSame('A1', $args['order_id']);
    }

    /**
     * --dry-run never mutates the sidecar.
     */
    public function test_sidecar_dry_run_reports_without_modifying(): void
    {
        $this->setUpHitlSidecar();

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--dry-run' => true])
            ->assertSuccessful();

        self::assertCount(2, $this->hitlRows());
    }

    /**
     * When only the sidecar is on database (audit.store != database), the command must succeed
     * for the sidecar — it must NOT hard-error just because audit isn't on database.
     */
    public function test_command_succeeds_when_only_sidecar_is_on_database(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'array');

        $this->setUpHitlSidecar();

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--actor' => 'ops'])
            ->assertSuccessful();

        // Sidecar old row was deleted
        $rows = $this->hitlRows();
        self::assertCount(1, $rows);
        self::assertSame('run-recent', $rows[0]->run_id);
    }

    /**
     * The actor is recorded per-table in the log for the sidecar sweep.
     */
    public function test_sidecar_purge_records_actor_in_log(): void
    {
        Log::spy();
        $this->setUpHitlSidecar();

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--actor' => 'eve'])
            ->assertSuccessful();

        // At minimum one log entry for the sidecar must reference the table + actor.
        Log::shouldHaveReceived('info')->withArgs(
            static fn (string $m, array $c): bool => str_contains($m, 'retention maintenance')
                && ($c['actor'] ?? '') === 'eve'
        )->atLeast()->once();
    }

    /**
     * Fail when NEITHER audit NOR sidecar is on database — nothing to sweep.
     */
    public function test_command_fails_when_neither_table_is_on_database(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'array');
        $this->app['config']->set('ai-guardrails.hitl_requests.store', 'array');

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--actor' => 'x'])
            ->assertFailed();
    }

    /**
     * `keep` strategy is a true no-op: it must succeed (exit 0) and mutate nothing
     * even when BOTH audit and hitl_requests stores are off-database.
     * This proves the "neither table on database" guard is bypassed for `keep`.
     */
    public function test_keep_strategy_succeeds_when_both_stores_are_off_database(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'array');
        $this->app['config']->set('ai-guardrails.hitl_requests.store', 'null');

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'keep'])->assertSuccessful();

        // The audit table (still in-memory from setUp) must be untouched.
        self::assertCount(2, $this->rows());
    }
}
