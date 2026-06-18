<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Console;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sanctioned, actor-audited GDPR-retention maintenance for the append-only injection audit (Task E5).
 *
 * The audit model refuses UPDATE and DELETE — this command is the ONLY place rows may be erased or
 * anonymised, and it does so through the raw query builder (bypassing the immutable model) so the
 * append-only invariant still holds for every other code path. Every run is logged with the actor,
 * the strategy, the cutoff, and the affected-row count.
 *
 * `retention.strategy`:
 *  - `keep`      — no-op (rows are retained indefinitely).
 *  - `anonymize` — null the prompt + principal of rows older than `retention.days` (keep the counts).
 *  - `purge`     — hard-delete rows older than `retention.days`.
 */
final class GuardrailsPurgeCommand extends Command
{
    protected $signature = 'ai-guardrails:purge
        {--strategy= : Override retention.strategy (anonymize|purge|keep)}
        {--days= : Override retention.days (rows strictly older than now-days are affected)}
        {--actor= : Who is running this maintenance (required unless --dry-run); recorded in the audit log}
        {--dry-run : Report how many rows would be affected without modifying anything}';

    protected $description = 'Apply the configured GDPR retention strategy to the append-only injection audit (actor-audited).';

    public function handle(): int
    {
        $strategy = (string) ($this->option('strategy') ?? $this->config('retention.strategy', 'anonymize'));
        if (! in_array($strategy, ['anonymize', 'purge', 'keep'], true)) {
            $this->error("Invalid strategy '{$strategy}'. Use anonymize|purge|keep.");

            return self::FAILURE;
        }

        if ($this->config('audit.store', 'null') !== 'database') {
            $this->error('ai-guardrails:purge requires audit.store=database (no persistent table otherwise).');

            return self::FAILURE;
        }

        $daysOption = $this->option('days');
        $days = max(0, $daysOption !== null ? (int) $daysOption : (int) $this->config('retention.days', 365));
        $dryRun = (bool) $this->option('dry-run');

        if ($strategy === 'keep') {
            $this->info("Retention strategy is 'keep' — nothing purged.");

            return self::SUCCESS;
        }

        // Accountability: a mutating run must name an actor (skipped only for a read-only dry-run).
        $actor = $this->option('actor');
        if (! $dryRun && (! is_string($actor) || trim($actor) === '')) {
            $this->error('--actor is required for a mutating run (omit only with --dry-run).');

            return self::FAILURE;
        }

        $cutoff = Carbon::now(new DateTimeZone('UTC'))->subDays($days)->format('Y-m-d H:i:s');
        $table = (string) $this->config('audit.table', 'ai_guardrails_injection_audit');
        $connection = $this->config('audit.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $candidates = fn () => DB::connection($connection)->table($table)->where('occurred_at', '<', $cutoff);

        if ($dryRun) {
            $count = $candidates()->count();
            $this->info("[dry-run] would {$strategy} {$count} row(s) older than {$cutoff} UTC.");

            return self::SUCCESS;
        }

        $affected = $strategy === 'purge'
            ? $candidates()->delete()
            : $candidates()->update([
                'prompt' => '[anonymized]',
                'principal_id' => null,
                'errored_rule_ids' => null,
                'match_start' => null,
                'match_end' => null,
            ]);

        // Actor-audited: a permanent record of WHO erased WHAT, so the erasure itself is accountable.
        Log::info('laravel-ai-guardrails: retention maintenance applied.', [
            'actor' => trim((string) $actor),
            'strategy' => $strategy,
            'days' => $days,
            'cutoff_utc' => $cutoff,
            'table' => $table,
            'affected' => $affected,
        ]);

        $this->info("{$strategy}: {$affected} row(s) older than {$cutoff} UTC (actor: ".trim((string) $actor).').');

        return self::SUCCESS;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("ai-guardrails.{$key}", $default);
    }
}
