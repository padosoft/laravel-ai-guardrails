<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

/**
 * Append-only database store. Inserts via the immutable InjectionAuditRecord model (which refuses
 * updates/deletes); reads most-recent-first. Connection + table are configurable.
 */
final readonly class DatabaseInjectionAuditStore implements InjectionAuditStore
{
    public function __construct(
        private ?string $connection,
        private string $table,
    ) {}

    public function append(InjectionAttempt $attempt): void
    {
        $this->newRecord()->fill([
            'prompt' => $attempt->prompt,
            'blocked' => $attempt->blocked,
            'rule_id' => $attempt->ruleId,
            'principal_id' => $attempt->principalId,
            // Persist in UTC so audit timestamps are unambiguous across deployments/timezones.
            'occurred_at' => $attempt->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function recent(int $limit = 50): array
    {
        // Reads go through the query builder (not Eloquent) for clean static analysis; writes use the
        // append-only model. Rows are stdClass.
        $rows = DB::connection($this->connection)
            ->table($this->table)
            ->orderByDesc('id')
            ->limit(max(0, $limit))
            ->get();

        $attempts = [];
        foreach ($rows as $row) {
            $attempts[] = new InjectionAttempt(
                (string) $row->prompt,
                (bool) $row->blocked,
                $row->rule_id !== null ? (string) $row->rule_id : null,
                $row->principal_id !== null ? (string) $row->principal_id : null,
                new DateTimeImmutable((string) $row->occurred_at, new DateTimeZone('UTC')),
            );
        }

        return $attempts;
    }

    private function newRecord(): InjectionAuditRecord
    {
        $record = new InjectionAuditRecord;
        $record->setTable($this->table);

        if ($this->connection !== null) {
            $record->setConnection($this->connection);
        }

        return $record;
    }
}
