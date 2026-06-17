<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;

/**
 * Append-only database firewall-rejection store. Inserts via the immutable FirewallRejectionRecord
 * model (which refuses updates/deletes); reads most-recent-first. Connection + table configurable.
 */
final readonly class DatabaseFirewallRejectionStore implements FirewallRejectionStore
{
    public function __construct(
        private ?string $connection,
        private string $table,
    ) {}

    public function record(FirewallRejection $rejection): void
    {
        $this->newRecord()->fill([
            'tool_description' => $rejection->toolDescription,
            'principal_id' => $rejection->principalId,
            'violations' => $rejection->violations,
            // Persist in UTC so timestamps are unambiguous across deployments/timezones.
            'occurred_at' => $rejection->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function query(FirewallQueryFilters $filters): FirewallPage
    {
        $query = $this->baseQuery();

        if ($filters->principalId !== null) {
            $query->where('principal_id', $filters->principalId);
        }
        if ($filters->search !== null) {
            $query->where('tool_description', 'like', '%'.$filters->search.'%');
        }
        if ($filters->from !== null) {
            $query->where('occurred_at', '>=', $filters->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }
        if ($filters->to !== null) {
            $query->where('occurred_at', '<=', $filters->to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }
        if ($filters->cursor !== null) {
            $query->where('id', '<', $filters->cursor);
        }

        // Fetch one extra row to learn whether a further page exists without a COUNT(*).
        $rows = $query->orderByDesc('id')->limit($filters->limit + 1)->get()->all();
        $hasMore = count($rows) > $filters->limit;
        $page = array_map($this->mapRow(...), array_slice($rows, 0, $filters->limit));
        $last = $page === [] ? null : $page[count($page) - 1]->id;

        return new FirewallPage($page, $hasMore ? $last : null);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    private function baseQuery(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }

    private function mapRow(\stdClass $row): FirewallRejection
    {
        $violations = [];
        if (isset($row->violations)) {
            // On SQLite/MySQL the driver returns JSON columns as strings; on PostgreSQL they may
            // arrive pre-decoded as arrays. Handle both to stay cross-driver compatible.
            $decoded = is_array($row->violations)
                ? $row->violations
                : (is_string($row->violations) ? json_decode($row->violations, true) : null);

            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($value)) {
                        $violations[(string) $key] = $value;
                    }
                }
            }
        }

        return new FirewallRejection(
            (string) $row->tool_description,
            $row->principal_id !== null ? (string) $row->principal_id : null,
            $violations,
            new DateTimeImmutable((string) $row->occurred_at, new DateTimeZone('UTC')),
            isset($row->id) && is_numeric($row->id) ? (int) $row->id : null,
        );
    }

    private function newRecord(): FirewallRejectionRecord
    {
        $record = new FirewallRejectionRecord;
        $record->setTable($this->table);

        if ($this->connection !== null) {
            $record->setConnection($this->connection);
        }

        return $record;
    }
}
