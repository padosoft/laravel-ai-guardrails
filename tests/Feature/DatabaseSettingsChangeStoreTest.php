<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use LogicException;
use Padosoft\AiGuardrails\Contracts\SettingsChangeStore;
use Padosoft\AiGuardrails\Settings\ArraySettingsChangeStore;
use Padosoft\AiGuardrails\Settings\DatabaseSettingsChangeStore;
use Padosoft\AiGuardrails\Settings\NullSettingsChangeStore;
use Padosoft\AiGuardrails\Settings\SettingsChange;
use Padosoft\AiGuardrails\Settings\SettingsChangeRecord;
use Padosoft\AiGuardrails\Tests\TestCase;

final class DatabaseSettingsChangeStoreTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/create_ai_guardrails_settings_changes_table.php.stub')->up();
    }

    private function store(): DatabaseSettingsChangeStore
    {
        return new DatabaseSettingsChangeStore(null, 'ai_guardrails_settings_changes');
    }

    public function test_record_then_recent_round_trips_most_recent_first(): void
    {
        $store = $this->store();
        $store->record(new SettingsChange('7', 'input_screen.enabled', true, false, new DateTimeImmutable('2026-01-01 10:00:00')));
        $store->record(new SettingsChange('7', 'hitl.fallback', 'deny', 'pass', new DateTimeImmutable('2026-01-01 10:05:00')));

        $recent = $store->recent();

        self::assertCount(2, $recent);
        self::assertSame('hitl.fallback', $recent[0]->key); // most recent first
        self::assertSame('deny', $recent[0]->oldValue);
        self::assertSame('pass', $recent[0]->newValue);
        self::assertSame('7', $recent[0]->actorId);
        // Boolean values round-trip through the JSON columns.
        self::assertTrue($recent[1]->oldValue);
        self::assertFalse($recent[1]->newValue);
        self::assertNotNull($recent[0]->id);
    }

    public function test_null_actor_round_trips(): void
    {
        $store = $this->store();
        $store->record(new SettingsChange(null, 'modes.hitl', 'enforce', 'monitor', new DateTimeImmutable('2026-01-01 10:00:00')));

        self::assertNull($store->recent()[0]->actorId);
    }

    public function test_the_log_is_append_only(): void
    {
        $this->store()->record(new SettingsChange('7', 'input_screen.enabled', true, false, new DateTimeImmutable('2026-01-01 10:00:00')));

        $record = SettingsChangeRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_binding_resolves_per_config(): void
    {
        $this->app['config']->set('ai-guardrails.settings_audit.store', 'array');
        $this->app->forgetInstance(SettingsChangeStore::class);
        self::assertInstanceOf(ArraySettingsChangeStore::class, $this->app->make(SettingsChangeStore::class));

        $this->app['config']->set('ai-guardrails.settings_audit.store', 'database');
        $this->app->forgetInstance(SettingsChangeStore::class);
        self::assertInstanceOf(DatabaseSettingsChangeStore::class, $this->app->make(SettingsChangeStore::class));

        $this->app['config']->set('ai-guardrails.settings_audit.store', 'null');
        $this->app->forgetInstance(SettingsChangeStore::class);
        self::assertInstanceOf(NullSettingsChangeStore::class, $this->app->make(SettingsChangeStore::class));
    }
}
