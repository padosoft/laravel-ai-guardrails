<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Audit\AuditPage;
use Padosoft\AiGuardrails\Audit\AuditQueryFilters;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;

interface InjectionAuditStore
{
    public function append(InjectionAttempt $attempt): void;

    /** @return list<InjectionAttempt> Most recent first. */
    public function recent(int $limit = 50): array;

    /** Filtered, keyset-paginated query for the admin audit list (GET /audit). */
    public function query(AuditQueryFilters $filters): AuditPage;

    /** Fetch a single attempt by id, or null (GET /audit/{id}). */
    public function find(int $id): ?InjectionAttempt;

    /**
     * Per-UTC-day attempt counts within [since, until] inclusive, oldest day first. The production
     * (database) store aggregates in SQL; in-memory stores bucket the rows they hold (GET /audit/trend).
     *
     * Three-way mutually exclusive split — invariant: total === blocked + observed + allowed.
     *   blocked  = blocked=true (rule matched AND was blocked)
     *   observed = blocked=false AND rule_id IS NOT NULL (monitor-mode match — detected but not blocked)
     *   allowed  = rule_id IS NULL (no rule matched at all)
     *
     * @return list<array{date:string,total:int,blocked:int,observed:int,allowed:int}>
     */
    public function trend(DateTimeImmutable $since, DateTimeImmutable $until): array;
}
