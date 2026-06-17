<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use LogicException;
use Padosoft\AiGuardrails\Output\DatabaseOutputStatStore;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Output\OutputStatRecord;
use Padosoft\AiGuardrails\Tests\TestCase;

final class DatabaseOutputStatStoreTest extends TestCase
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

        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_output_stats_table.php.stub';
        $migration->up();
    }

    private function store(): DatabaseOutputStatStore
    {
        return new DatabaseOutputStatStore(null, 'ai_guardrails_output_stats');
    }

    public function test_record_then_totals_aggregates_in_sql(): void
    {
        $store = $this->store();
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::PiiRedaction, 4);

        $totals = $store->totals();
        self::assertSame(2, $totals[OutputStatKind::HtmlStripped->value]);
        self::assertSame(4, $totals[OutputStatKind::PiiRedaction->value]);
        self::assertSame(6, $store->count());
    }

    public function test_record_ignores_non_positive_counts(): void
    {
        $store = $this->store();
        $store->record(OutputStatKind::HtmlStripped, 0);

        self::assertSame(0, $store->count());
    }

    public function test_record_is_append_only_update_throws(): void
    {
        $this->store()->record(OutputStatKind::HtmlStripped);

        $record = OutputStatRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->update(['event_count' => 999]);
    }

    public function test_builder_mass_delete_throws(): void
    {
        $this->store()->record(OutputStatKind::HtmlStripped);

        $this->expectException(LogicException::class);
        OutputStatRecord::query()->delete();
    }
}
