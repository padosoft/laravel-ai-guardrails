<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Tests\TestCase;

final class GuardrailsPurgeCommandTest extends TestCase
{
    private const TABLE = 'ai_guardrails_injection_audit';

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

    public function test_requires_database_store(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'array');

        $this->artisan('ai-guardrails:purge', ['--strategy' => 'purge', '--days' => '30', '--actor' => 'x'])
            ->assertFailed();
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
}
