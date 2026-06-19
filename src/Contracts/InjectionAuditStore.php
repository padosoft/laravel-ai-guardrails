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
     * v1.0 invariant (preserved): total === blocked + allowed.
     *   blocked  = blocked=true (rule matched AND was blocked)
     *   allowed  = NOT blocked (every non-blocked attempt — includes monitor-mode matches)
     *
     * v1.1.0 additive field: observed ⊆ allowed.
     *   observed = NOT blocked AND rule_id IS NOT NULL (monitor-mode match — detected but not blocked)
     *
     * A consumer wanting the disjoint "no rule matched" series computes: allowed - observed.
     * The degenerate row (blocked=true, rule_id=null) counts as `blocked` only (never as `allowed`).
     *
     * @return list<array{date:string,total:int,blocked:int,allowed:int,observed:int}>
     */
    public function trend(DateTimeImmutable $since, DateTimeImmutable $until): array;
}
