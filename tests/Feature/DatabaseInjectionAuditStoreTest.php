<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use LogicException;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Audit\InjectionAuditRecord;
use Padosoft\AiGuardrails\Tests\TestCase;

final class DatabaseInjectionAuditStoreTest extends TestCase
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

        // Build the table from the published migration stub so the schema is the real one.
        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_injection_audit_table.php.stub';
        $migration->up();
    }

    private function store(): DatabaseInjectionAuditStore
    {
        return new DatabaseInjectionAuditStore(null, 'ai_guardrails_injection_audit');
    }

    public function test_append_then_recent_round_trips_most_recent_first(): void
    {
        $store = $this->store();

        $store->append(new InjectionAttempt('first', false, null, '42', new DateTimeImmutable('2026-01-01 10:00:00')));
        $store->append(new InjectionAttempt('ignore previous', true, 'ignore_previous', '42', new DateTimeImmutable('2026-01-01 10:05:00')));

        $recent = $store->recent(10);

        self::assertCount(2, $recent);
        self::assertSame('ignore previous', $recent[0]->prompt); // most recent first
        self::assertTrue($recent[0]->blocked);
        self::assertSame('ignore_previous', $recent[0]->ruleId);
        self::assertSame('first', $recent[1]->prompt);
        self::assertFalse($recent[1]->blocked);
    }

    public function test_record_is_append_only_update_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $record = InjectionAuditRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->update(['prompt' => 'tampered']);
    }

    public function test_record_is_append_only_delete_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $record = InjectionAuditRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_builder_mass_delete_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $this->expectException(LogicException::class);
        InjectionAuditRecord::query()->delete();
    }
}
