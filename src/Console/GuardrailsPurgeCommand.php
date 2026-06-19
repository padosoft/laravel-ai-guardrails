<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Console;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sanctioned, actor-audited GDPR-retention maintenance for the append-only injection audit (Task E5)
 * and the HITL request sidecar (Task 6 — GDPR gap closure for ai_guardrails_hitl_requests).
 *
 * Both audit models refuse UPDATE and DELETE — this command is the ONLY place rows may be erased or
 * anonymised, and it does so through the raw query builder (bypassing the immutable model) so the
 * append-only invariant still holds for every other code path. Every run is logged with the actor,
 * the strategy, the cutoff, and the affected-row count per table.
 *
 * `retention.strategy`:
 *  - `keep`      — no-op (rows are retained indefinitely).
 *  - `anonymize` — set audit `prompt` to `'[anonymized]'` + null `principal_id` on audit rows;
 *                  redact `arguments` to `{}` + null `principal_id` on sidecar rows;
 *                  all rows older than `retention.days`.
 *  - `purge`     — hard-delete rows older than `retention.days` (both tables when on database).
 *
 * The command proceeds if AT LEAST ONE of the two tables is on `database`. If both are on database
 * both are swept in the same run. If neither is on database the command errors (nothing to do).
 */
final class GuardrailsPurgeCommand extends Command
{
    protected $signature = 'ai-guardrails:purge
        {--strategy= : Override retention.strategy (anonymize|purge|keep)}
        {--days= : Override retention.days (rows strictly older than now-days are affected)}
        {--actor= : Who is running this maintenance (required unless --dry-run); recorded in the audit log}
        {--dry-run : Report how many rows would be affected without modifying anything}';

    protected $description = 'Apply the configured GDPR retention strategy to the append-only injection audit and HITL request sidecar (actor-audited).';

    public function handle(): int
    {
        $strategy = (string) ($this->option('strategy') ?? $this->config('retention.strategy', 'anonymize'));
        if (! in_array($strategy, ['anonymize', 'purge', 'keep'], true)) {
            $this->error("Invalid strategy '{$strategy}'. Use anonymize|purge|keep.");

            return self::FAILURE;
        }

        // `keep` is a true no-op regardless of which store is configured.
        if ($strategy === 'keep') {
            $this->info("Retention strategy is 'keep' — nothing purged.");

            return self::SUCCESS;
        }

        $auditOnDb = $this->config('audit.store', 'null') === 'database';
        $sidecarOnDb = $this->config('hitl_requests.store', 'null') === 'database';

        if (! $auditOnDb && ! $sidecarOnDb) {
            $this->error('ai-guardrails:purge requires audit.store=database or hitl_requests.store=database (no persistent table otherwise).');

            return self::FAILURE;
        }

        $daysOption = $this->option('days');
        $days = max(0, $daysOption !== null ? (int) $daysOption : (int) $this->config('retention.days', 365));
        $dryRun = (bool) $this->option('dry-run');

        // Accountability: a mutating run must name an actor (skipped only for a read-only dry-run).
        $actor = $this->option('actor');
        if (! $dryRun && (! is_string($actor) || trim($actor) === '')) {
            $this->error('--actor is required for a mutating run (omit only with --dry-run).');

            return self::FAILURE;
        }

        // Guard against --days=0 on a mutating run: cutoff would be ~now(), matching every row.
        if (! $dryRun && $days < 1) {
            $this->error('--days must be >= 1 for a mutating run (use --dry-run to preview a days=0 count).');

            return self::FAILURE;
        }

        $cutoff = Carbon::now(new DateTimeZone('UTC'))->subDays($days)->format('Y-m-d H:i:s');
        $actorStr = is_string($actor) ? trim($actor) : '';

        // ── Audit table ───────────────────────────────────────────────────────
        if ($auditOnDb) {
            $this->sweepAuditTable($strategy, $days, $cutoff, $actorStr, $dryRun);
        }

        // ── HITL request sidecar ──────────────────────────────────────────────
        if ($sidecarOnDb) {
            $this->sweepSidecarTable($strategy, $days, $cutoff, $actorStr, $dryRun);
        }

        return self::SUCCESS;
    }

    private function sweepAuditTable(
        string $strategy,
        int $days,
        string $cutoff,
        string $actor,
        bool $dryRun,
    ): void {
        $table = (string) $this->config('audit.table', 'ai_guardrails_injection_audit');
        $connection = $this->config('audit.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $candidates = fn () => DB::connection($connection)->table($table)->where('occurred_at', '<', $cutoff);

        if ($dryRun) {
            $count = $candidates()->count();
            $this->info("[dry-run] audit: would {$strategy} {$count} row(s) older than {$cutoff} UTC.");

            return;
        }

        Log::info('laravel-ai-guardrails: retention maintenance starting.', [
            'actor' => $actor,
            'strategy' => $strategy,
            'days' => $days,
            'cutoff_utc' => $cutoff,
            'table' => $table,
        ]);

        $affected = $strategy === 'purge'
            ? $candidates()->delete()
            : $candidates()->update([
                'prompt' => '[anonymized]',
                'principal_id' => null,
                'errored_rule_ids' => null,
                'match_start' => null,
                'match_end' => null,
            ]);

        Log::info('laravel-ai-guardrails: retention maintenance applied.', [
            'actor' => $actor,
            'strategy' => $strategy,
            'days' => $days,
            'cutoff_utc' => $cutoff,
            'table' => $table,
            'affected' => $affected,
        ]);

        $this->info("audit {$strategy}: {$affected} row(s) older than {$cutoff} UTC (actor: {$actor}).");
    }

    private function sweepSidecarTable(
        string $strategy,
        int $days,
        string $cutoff,
        string $actor,
        bool $dryRun,
    ): void {
        $table = (string) $this->config('hitl_requests.table', 'ai_guardrails_hitl_requests');
        $connection = $this->config('hitl_requests.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $candidates = fn () => DB::connection($connection)->table($table)->where('occurred_at', '<', $cutoff);

        if ($dryRun) {
            $count = $candidates()->count();
            $this->info("[dry-run] hitl_requests: would {$strategy} {$count} row(s) older than {$cutoff} UTC.");

            return;
        }

        Log::info('laravel-ai-guardrails: retention maintenance starting.', [
            'actor' => $actor,
            'strategy' => $strategy,
            'days' => $days,
            'cutoff_utc' => $cutoff,
            'table' => $table,
        ]);

        // purge: hard-delete old rows.
        // anonymize: redact arguments to {} and null principal_id; keep run_id, tool, occurred_at
        //   so the approval trail survives but the PII is gone.
        $affected = $strategy === 'purge'
            ? $candidates()->delete()
            : $candidates()->update([
                'arguments' => '{}',
                'principal_id' => null,
            ]);

        Log::info('laravel-ai-guardrails: retention maintenance applied.', [
            'actor' => $actor,
            'strategy' => $strategy,
            'days' => $days,
            'cutoff_utc' => $cutoff,
            'table' => $table,
            'affected' => $affected,
        ]);

        $this->info("hitl_requests {$strategy}: {$affected} row(s) older than {$cutoff} UTC (actor: {$actor}).");
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("ai-guardrails.{$key}", $default);
    }
}
