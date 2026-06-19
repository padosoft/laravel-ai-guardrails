<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\HitlRequestStore;

/**
 * Append-only database HITL request sidecar store. Inserts via the immutable HitlRequestRecord
 * model (which refuses updates/deletes); reads most-recent-first per run_id.
 */
final readonly class DatabaseHitlRequestStore implements HitlRequestStore
{
    public function __construct(
        private ?string $connection,
        private string $table,
    ) {}

    public function record(string $runId, string $tool, array $arguments, int|string|null $principalId): void
    {
        $record = new HitlRequestRecord;
        $record->setTable($this->table);

        if ($this->connection !== null) {
            $record->setConnection($this->connection);
        }

        $record->fill([
            'run_id' => $runId,
            'tool' => $tool,
            'arguments' => $arguments,
            'principal_id' => $principalId !== null ? (string) $principalId : null,
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function forRunIds(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $rows = DB::connection($this->connection)
            ->table($this->table)
            ->whereIn('run_id', $runIds)
            ->orderByDesc('id') // most-recent first
            ->get(['run_id', 'tool', 'arguments'])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $runId = (string) $row->run_id;
            if (isset($map[$runId])) {
                continue; // most-recent already set (DESC order)
            }

            $arguments = [];
            if (isset($row->arguments)) {
                $decoded = is_array($row->arguments)
                    ? $row->arguments
                    : (is_string($row->arguments) ? json_decode($row->arguments, true) : null);
                $arguments = is_array($decoded) ? $decoded : [];
            }

            $map[$runId] = [
                'tool' => (string) $row->tool,
                'arguments' => $arguments,
            ];
        }

        return $map;
    }
}
