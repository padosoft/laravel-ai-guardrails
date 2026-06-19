<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use LogicException;
use Padosoft\AiGuardrails\Hitl\ArrayHitlRequestStore;
use Padosoft\AiGuardrails\Hitl\DatabaseHitlRequestStore;
use Padosoft\AiGuardrails\Hitl\HitlRequestRecord;
use Padosoft\AiGuardrails\Tests\TestCase;

final class HitlRequestStoreTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_hitl_requests_table.php.stub';
        $migration->up();
    }

    private function store(): DatabaseHitlRequestStore
    {
        return new DatabaseHitlRequestStore(null, 'ai_guardrails_hitl_requests');
    }

    // ── ArrayHitlRequestStore ─────────────────────────────────────────────────

    public function test_array_store_record_and_for_run_ids(): void
    {
        $store = new ArrayHitlRequestStore;
        $store->record('run-1', 'refund', ['order_id' => 'X1'], 9);
        $store->record('run-2', 'delete', ['id' => 5], null);

        $map = $store->forRunIds(['run-1', 'run-2', 'run-missing']);

        self::assertArrayHasKey('run-1', $map);
        self::assertSame('refund', $map['run-1']['tool']);
        self::assertSame(['order_id' => 'X1'], $map['run-1']['arguments']);
        self::assertArrayHasKey('run-2', $map);
        self::assertArrayNotHasKey('run-missing', $map);
    }

    public function test_array_store_most_recent_wins_for_duplicate_run_id(): void
    {
        $store = new ArrayHitlRequestStore;
        $store->record('run-1', 'refund', ['x' => 1], null);
        $store->record('run-1', 'delete', ['y' => 2], null);

        $map = $store->forRunIds(['run-1']);
        self::assertSame('delete', $map['run-1']['tool']);
    }

    public function test_array_store_empty_run_ids_returns_empty_map(): void
    {
        $store = new ArrayHitlRequestStore;
        self::assertSame([], $store->forRunIds([]));
    }

    // ── DatabaseHitlRequestStore ──────────────────────────────────────────────

    public function test_database_store_record_and_for_run_ids(): void
    {
        $store = $this->store();
        $store->record('run-A', 'send_email', ['to' => 'foo@bar.com'], 42);

        $map = $store->forRunIds(['run-A', 'run-missing']);

        self::assertArrayHasKey('run-A', $map);
        self::assertSame('send_email', $map['run-A']['tool']);
        self::assertSame(['to' => 'foo@bar.com'], $map['run-A']['arguments']);
        self::assertArrayNotHasKey('run-missing', $map);
    }

    public function test_database_store_most_recent_wins_for_duplicate_run_id(): void
    {
        $store = $this->store();
        $store->record('run-X', 'refund', ['a' => 1], null);
        $store->record('run-X', 'delete', ['b' => 2], null);

        $map = $store->forRunIds(['run-X']);
        self::assertSame('delete', $map['run-X']['tool']);
    }

    // ── Append-only model ─────────────────────────────────────────────────────

    public function test_model_update_throws(): void
    {
        $this->store()->record('run-1', 'refund', [], null);

        $record = HitlRequestRecord::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $record->update(['tool' => 'tampered']);
    }

    public function test_model_delete_throws(): void
    {
        $this->store()->record('run-1', 'refund', [], null);

        $record = HitlRequestRecord::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_builder_mass_update_throws(): void
    {
        $this->store()->record('run-1', 'refund', [], null);

        $this->expectException(LogicException::class);
        HitlRequestRecord::query()->update(['tool' => 'tampered']);
    }

    public function test_builder_truncate_throws(): void
    {
        $this->store()->record('run-1', 'refund', [], null);

        $this->expectException(LogicException::class);
        HitlRequestRecord::query()->truncate();
    }
}
