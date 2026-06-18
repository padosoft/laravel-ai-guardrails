<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use ReflectionClass;

/**
 * Turnkey HITL (Control D) setup (Task L4): runs the laravel-flow migrations so the flow_runs /
 * flow_approvals tables exist, then prints the recommended configuration. Guarded by laravel-flow
 * being installed (clear message + non-zero exit otherwise). Idempotent — Laravel's migrator records
 * which migrations ran, so re-running is safe.
 *
 * The migrations are run straight from the vendor directory via `migrate --path` (no vendor:publish),
 * scoped to ONLY laravel-flow's migrations so no unrelated pending host migrations are triggered.
 *
 * Lives in src/Hitl so the laravel-flow reference stays inside the adapter boundary (compose-not-couple).
 */
final class HitlInstallCommand extends Command
{
    protected $signature = 'ai-guardrails:hitl-install';

    protected $description = 'Run the laravel-flow migrations (flow_runs/flow_approvals) and print the recommended HITL config.';

    public function handle(): int
    {
        if (! class_exists(LaravelFlowServiceProvider::class)) {
            $this->error('laravel-flow is not installed. Run:  composer require padosoft/laravel-flow');

            return self::FAILURE;
        }

        $this->info('Running laravel-flow migrations…');
        $this->call('migrate', [
            // Absolute path to the vendor migrations — scoped so only flow's tables are created.
            '--path' => $this->flowMigrationsPath(),
            '--realpath' => true,
            '--force' => true,
        ]);

        if (! $this->hasTable('flow_runs') || ! $this->hasTable('flow_approvals')) {
            $this->error('Migrations ran but the flow tables are still missing — check your database connection.');

            return self::FAILURE;
        }

        $this->info('HITL tables are ready. Recommended configuration:');
        $this->line('  LARAVEL_FLOW_PERSISTENCE_ENABLED=true');
        $this->line('  AI_GUARDRAILS_HITL_ENABLED=true');
        $this->line('Then verify with:  php artisan ai-guardrails:hitl-status');

        return self::SUCCESS;
    }

    /** Absolute path to laravel-flow's migrations, resolved from the provider location (vendor-safe). */
    private function flowMigrationsPath(): string
    {
        $providerFile = (string) (new ReflectionClass(LaravelFlowServiceProvider::class))->getFileName();

        return dirname($providerFile, 2).'/database/migrations';
    }

    private function hasTable(string $table): bool
    {
        return (bool) rescue(static fn (): bool => Schema::hasTable($table), false, false);
    }
}
