<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Padosoft\AiGuardrails\Contracts\GatedToolCallStore;
use Padosoft\AiGuardrails\Firewall\DatabaseFirewallRejectionStore;

/**
 * Append-only database store for Control P decisions. Mirrors
 * {@see DatabaseFirewallRejectionStore}
 * down to the LIKE escaping and the fetch-one-extra pagination, so there
 * is one pattern in this package rather than two.
 */
final readonly class DatabaseGatedToolCallStore implements GatedToolCallStore
{
    public function __construct(
        private ?string $connection,
        private string $table,
    ) {}

    public function record(GatedToolCall $call): void
    {
        $this->newRecord()->fill([
            'tool_name' => $call->toolName,
            'principal_id' => $call->principalId,
            'tiers' => $call->tiers,
            'blocked' => $call->blocked,
            // Persist in UTC so timestamps are unambiguous across deployments/timezones.
            'occurred_at' => $call->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function query(GatedToolCallFilters $filters): GatedToolCallPage
    {
        $query = $this->baseQuery();

        if ($filters->principalId !== null) {
            $query->where('principal_id', $filters->principalId);
        }
        if ($filters->search !== null) {
            // Escape LIKE metacharacters so the term is a literal substring.
            // '!' as ESCAPE is portable across MySQL, PostgreSQL, SQLite, SQL Server.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters->search);
            $query->whereRaw("tool_name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }
        if ($filters->blocked !== null) {
            $query->where('blocked', $filters->blocked);
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

        // One extra row tells us whether a further page exists without a COUNT(*).
        $rows = $query->orderByDesc('id')->limit($filters->limit + 1)->get()->all();
        $hasMore = count($rows) > $filters->limit;
        $page = array_map($this->mapRow(...), array_slice($rows, 0, $filters->limit));
        $last = $page === [] ? null : $page[count($page) - 1]->id;

        return new GatedToolCallPage($page, $hasMore ? $last : null);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    private function baseQuery(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }

    private function mapRow(\stdClass $row): GatedToolCall
    {
        $tiers = [];
        if (isset($row->tiers)) {
            // SQLite/MySQL hand back JSON columns as strings; PostgreSQL may
            // pre-decode them. Handle both to stay cross-driver compatible.
            $decoded = is_array($row->tiers)
                ? $row->tiers
                : (is_string($row->tiers) ? json_decode($row->tiers, true) : null);

            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    if (is_string($value)) {
                        $tiers[] = $value;
                    }
                }
            }
        }

        return new GatedToolCall(
            (string) $row->tool_name,
            $row->principal_id !== null ? (string) $row->principal_id : null,
            $tiers,
            (bool) $row->blocked,
            new DateTimeImmutable((string) $row->occurred_at, new DateTimeZone('UTC')),
            isset($row->id) && is_numeric($row->id) ? (int) $row->id : null,
        );
    }

    private function newRecord(): GatedToolCallRecord
    {
        $record = new GatedToolCallRecord;
        $record->setTable($this->table);

        if ($this->connection !== null) {
            $record->setConnection($this->connection);
        }

        return $record;
    }
}
