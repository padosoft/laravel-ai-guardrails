<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
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
        $span = $attempt->matchedSpan;

        $this->newRecord()->fill([
            'prompt' => $attempt->prompt,
            'blocked' => $attempt->blocked,
            'rule_id' => $attempt->ruleId,
            'principal_id' => $attempt->principalId,
            'ruleset_version' => $attempt->rulesetVersion,
            'errored_rule_ids' => $attempt->erroredRuleIds !== [] ? $attempt->erroredRuleIds : null,
            'match_start' => $span !== null ? $span[0] : null,
            'match_end' => $span !== null ? $span[1] : null,
            // Persist in UTC so audit timestamps are unambiguous across deployments/timezones.
            'occurred_at' => $attempt->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ])->save();
    }

    public function recent(int $limit = 50): array
    {
        $rows = $this->baseQuery()
            ->orderByDesc('id')
            ->limit(max(0, $limit))
            ->get();

        return array_values(array_map($this->mapRow(...), $rows->all()));
    }

    public function query(AuditQueryFilters $filters): AuditPage
    {
        $query = $this->baseQuery();

        if ($filters->blocked !== null) {
            $query->where('blocked', $filters->blocked);
        }
        if ($filters->ruleId !== null) {
            $query->where('rule_id', $filters->ruleId);
        }
        if ($filters->principalId !== null) {
            $query->where('principal_id', $filters->principalId);
        }
        if ($filters->search !== null) {
            // Escape LIKE metacharacters so the search term is treated as a literal substring.
            // Use '!' as the ESCAPE character — portable across MySQL, PostgreSQL, SQLite, SQL Server.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters->search);
            $query->whereRaw("prompt LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }
        if ($filters->from !== null) {
            $query->where('occurred_at', '>=', $filters->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }
        if ($filters->to !== null) {
            $query->where('occurred_at', '<=', $filters->to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }
        // Keyset pagination on the monotonic id (newest first).
        if ($filters->cursor !== null) {
            $query->where('id', '<', $filters->cursor);
        }

        // Fetch one extra row to learn whether a further page exists without a COUNT(*).
        $rows = $query->orderByDesc('id')->limit($filters->limit + 1)->get()->all();
        $hasMore = count($rows) > $filters->limit;
        $page = array_map($this->mapRow(...), array_slice($rows, 0, $filters->limit));
        $last = $page === [] ? null : $page[count($page) - 1]->id;

        return new AuditPage($page, $hasMore ? $last : null);
    }

    public function find(int $id): ?InjectionAttempt
    {
        $row = $this->baseQuery()->where('id', $id)->first();

        return $row !== null ? $this->mapRow((object) $row) : null;
    }

    public function trend(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $utc = new DateTimeZone('UTC');
        $day = $this->dayExpression();
        $isBlocked = $this->blockedPredicate();

        $notBlocked = $this->notBlockedPredicate();

        $rows = $this->baseQuery()
            ->selectRaw(
                $day.' as day'
                .', count(*) as total'
                .', sum(case when '.$isBlocked.' then 1 else 0 end) as blocked'
                .', sum(case when '.$notBlocked.' AND rule_id IS NOT NULL then 1 else 0 end) as observed'
                .', sum(case when '.$notBlocked.' AND rule_id IS NULL then 1 else 0 end) as allowed'
            )
            ->where('occurred_at', '>=', $since->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<=', $until->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->groupByRaw($day)
            ->orderByRaw($day.' asc')
            ->get();

        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'date' => (string) $row->day,
                'total' => (int) $row->total,
                'blocked' => (int) $row->blocked,
                'observed' => (int) $row->observed,
                'allowed' => (int) $row->allowed,
            ];
        }

        return $points;
    }

    /**
     * Dialect-safe expression that truncates the UTC `occurred_at` timestamp to a YYYY-MM-DD string.
     * No user input — safe to embed in a raw SQL fragment.
     *
     * @return literal-string
     */
    private function dayExpression(): string
    {
        $driver = DB::connection($this->connection)->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => 'DATE(occurred_at)',
            'pgsql' => "to_char(occurred_at, 'YYYY-MM-DD')",
            'sqlsrv' => 'CONVERT(varchar(10), occurred_at, 23)',
            default => "strftime('%Y-%m-%d', occurred_at)", // sqlite
        };
    }

    /**
     * Dialect-safe truthy test for the `blocked` column. PostgreSQL stores it as a real boolean and
     * rejects `blocked = 1`; MySQL/SQLite (int 0/1) and SQL Server (bit) accept `blocked = 1`.
     *
     * @return literal-string
     */
    private function blockedPredicate(): string
    {
        return DB::connection($this->connection)->getDriverName() === 'pgsql'
            ? 'blocked = true'
            : 'blocked = 1';
    }

    /**
     * Dialect-safe falsy test for the `blocked` column — the explicit complement of `blockedPredicate()`.
     * Using an explicit `= false` / `= 0` form (rather than `NOT blocked = 1`) avoids any operator-
     * precedence ambiguity across dialects and makes the three CASE branches mutually exclusive by
     * construction: blocked / (not-blocked AND rule_id IS NOT NULL) / (not-blocked AND rule_id IS NULL).
     *
     * @return literal-string
     */
    private function notBlockedPredicate(): string
    {
        return DB::connection($this->connection)->getDriverName() === 'pgsql'
            ? 'blocked = false'
            : 'blocked = 0';
    }

    private function baseQuery(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }

    private function mapRow(\stdClass $row): InjectionAttempt
    {
        $erroredRuleIds = [];
        if (isset($row->errored_rule_ids) && is_string($row->errored_rule_ids)) {
            $decoded = json_decode($row->errored_rule_ids, true);
            $erroredRuleIds = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        $span = null;
        if (isset($row->match_start, $row->match_end) && is_numeric($row->match_start) && is_numeric($row->match_end)) {
            $span = [(int) $row->match_start, (int) $row->match_end];
        }

        return new InjectionAttempt(
            (string) $row->prompt,
            (bool) $row->blocked,
            $row->rule_id !== null ? (string) $row->rule_id : null,
            $row->principal_id !== null ? (string) $row->principal_id : null,
            new DateTimeImmutable((string) $row->occurred_at, new DateTimeZone('UTC')),
            $row->ruleset_version !== null ? (string) $row->ruleset_version : null,
            $erroredRuleIds,
            $span,
            isset($row->id) && is_numeric($row->id) ? (int) $row->id : null,
        );
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
