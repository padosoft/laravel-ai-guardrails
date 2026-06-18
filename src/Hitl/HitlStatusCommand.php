<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;

/**
 * Diagnoses the HITL (Control D) approval bridge setup (Task L4). Control D's flow persistence —
 * approval tokens, resume — is configured by the host; this command makes that setup verifiable:
 * it reports each prerequisite (laravel-flow installed, persistence enabled, the flow tables
 * migrated, the master + hitl toggles on) and exits non-zero when HITL cannot actually gate a call.
 *
 * Lives in src/Hitl so the laravel-flow reference stays inside the adapter boundary (compose-not-couple).
 */
final class HitlStatusCommand extends Command
{
    protected $signature = 'ai-guardrails:hitl-status';

    protected $description = 'Diagnose the HITL (Control D) approval-bridge setup; non-zero exit when misconfigured.';

    public function handle(): int
    {
        $flowInstalled = class_exists(LaravelFlowServiceProvider::class);
        $persistence = (bool) config('laravel-flow.persistence.enabled', false);
        $runsTable = $flowInstalled && $this->hasTable('flow_runs');
        $approvalsTable = $flowInstalled && $this->hasTable('flow_approvals');
        $masterEnabled = (bool) config('ai-guardrails.enabled', true);
        $hitlEnabled = (bool) config('ai-guardrails.hitl.enabled', false);

        $ok = static fn (bool $v): string => $v ? 'OK' : '—';
        $this->table(['Check', 'Status'], [
            ['laravel-flow installed', $ok($flowInstalled)],
            ['flow persistence enabled', $ok($persistence)],
            ['flow_runs table', $ok($runsTable)],
            ['flow_approvals table', $ok($approvalsTable)],
            ['ai-guardrails master enabled', $ok($masterEnabled)],
            ['hitl.enabled', $ok($hitlEnabled)],
        ]);

        if ($flowInstalled && $persistence && $runsTable && $approvalsTable && $masterEnabled && $hitlEnabled) {
            $this->info('HITL is fully configured and active.');

            return self::SUCCESS;
        }

        if (! $flowInstalled) {
            $this->warn('Install laravel-flow:  composer require padosoft/laravel-flow');
        }
        if ($flowInstalled && (! $runsTable || ! $approvalsTable)) {
            $this->warn('Create the flow tables:  php artisan ai-guardrails:hitl-install');
        }
        if (! $persistence) {
            $this->warn('Enable flow persistence:  LARAVEL_FLOW_PERSISTENCE_ENABLED=true');
        }
        if (! $hitlEnabled) {
            $this->warn('Enable the HITL bridge:  AI_GUARDRAILS_HITL_ENABLED=true');
        }
        if (! $masterEnabled) {
            $this->warn('The master kill-switch is off:  AI_GUARDRAILS_ENABLED=true');
        }

        return self::FAILURE;
    }

    /** Probe a table defensively — no DB connection configured must not throw, just report "missing". */
    private function hasTable(string $table): bool
    {
        return (bool) rescue(static fn (): bool => Schema::hasTable($table), false, false);
    }
}
