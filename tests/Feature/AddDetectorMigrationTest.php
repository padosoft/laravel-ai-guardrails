<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\AiGuardrails\Output\DatabaseOutputStatStore;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Regression test: the v1.1.0 additive migration correctly adds the `detector` column
 * to an existing ai_guardrails_output_stats table (simulating an upgrade from v1.0.x).
 */
final class AddDetectorMigrationTest extends TestCase
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

    /**
     * Simulate a v1.0.x install: create the table WITHOUT the detector column.
     */
    private function createTableWithoutDetector(): void
    {
        Schema::create('ai_guardrails_output_stats', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('kind')->index();
            $table->unsignedInteger('event_count')->default(1);
            // Intentionally omit `detector` — this is the pre-v1.1 schema.
            $table->timestamp('occurred_at')->index();
        });
    }

    public function test_additive_migration_adds_detector_column_to_existing_table(): void
    {
        $this->createTableWithoutDetector();

        self::assertFalse(
            Schema::hasColumn('ai_guardrails_output_stats', 'detector'),
            'Pre-condition: detector column must not exist before the additive migration'
        );

        $migration = require __DIR__.'/../../database/migrations/add_detector_to_ai_guardrails_output_stats_table.php.stub';
        $migration->up();

        self::assertTrue(
            Schema::hasColumn('ai_guardrails_output_stats', 'detector'),
            'detector column must exist after the additive migration'
        );
    }

    public function test_additive_migration_is_idempotent_on_fresh_install(): void
    {
        // Fresh-install path: create-table stub already includes the column.
        $createMigration = require __DIR__.'/../../database/migrations/create_ai_guardrails_output_stats_table.php.stub';
        $createMigration->up();

        self::assertTrue(Schema::hasColumn('ai_guardrails_output_stats', 'detector'));

        // Running the additive migration again must not throw.
        $addMigration = require __DIR__.'/../../database/migrations/add_detector_to_ai_guardrails_output_stats_table.php.stub';
        $addMigration->up();

        self::assertTrue(Schema::hasColumn('ai_guardrails_output_stats', 'detector'));
    }

    public function test_store_records_and_queries_detector_after_additive_migration(): void
    {
        $this->createTableWithoutDetector();

        $migration = require __DIR__.'/../../database/migrations/add_detector_to_ai_guardrails_output_stats_table.php.stub';
        $migration->up();

        $store = new DatabaseOutputStatStore(null, 'ai_guardrails_output_stats');
        $store->record(OutputStatKind::PiiRedaction, 2, 'email');
        $store->record(OutputStatKind::PiiRedaction, 1); // null detector — legacy row

        $byDetector = $store->byDetector();
        self::assertSame(['email' => 2], $byDetector);

        // Total across both rows must be 3.
        self::assertSame(3, $store->count());
    }

    public function test_additive_migration_down_removes_detector_column(): void
    {
        $this->createTableWithoutDetector();

        $migration = require __DIR__.'/../../database/migrations/add_detector_to_ai_guardrails_output_stats_table.php.stub';
        $migration->up();
        self::assertTrue(Schema::hasColumn('ai_guardrails_output_stats', 'detector'));

        $migration->down();
        self::assertFalse(Schema::hasColumn('ai_guardrails_output_stats', 'detector'));
    }
}
