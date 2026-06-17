<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\OutputStatStore;

/**
 * Append-only database output-stat store. Inserts via the immutable OutputStatRecord model (which
 * refuses updates/deletes); aggregates counts in SQL. Connection + table configurable.
 */
final readonly class DatabaseOutputStatStore implements OutputStatStore
{
    public function __construct(
        private ?string $connection,
        private string $table,
    ) {}

    public function record(OutputStatKind $kind, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $this->newRecord()->fill([
            'kind' => $kind->value,
            'event_count' => $count,
            // Persist in UTC so timestamps are unambiguous across deployments/timezones.
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function totals(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $query = $this->baseQuery();
        $this->applyWindow($query, $from, $to);

        $rows = $query->groupBy('kind')->selectRaw('kind, sum(event_count) as total')->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->kind] = (int) $row->total;
        }

        return $totals;
    }

    public function count(): int
    {
        return (int) $this->baseQuery()->sum('event_count');
    }

    private function baseQuery(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }

    private function applyWindow(Builder $query, ?DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        $utc = new DateTimeZone('UTC');
        if ($from !== null) {
            $query->where('occurred_at', '>=', $from->setTimezone($utc)->format('Y-m-d H:i:s'));
        }
        if ($to !== null) {
            $query->where('occurred_at', '<=', $to->setTimezone($utc)->format('Y-m-d H:i:s'));
        }
    }

    private function newRecord(): OutputStatRecord
    {
        $record = new OutputStatRecord;
        $record->setTable($this->table);

        if ($this->connection !== null) {
            $record->setConnection($this->connection);
        }

        return $record;
    }
}
