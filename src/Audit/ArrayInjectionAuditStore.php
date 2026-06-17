<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

/**
 * In-memory append-only store (tests / default). Never mutates existing entries; assigns a
 * sequential id on append so the list/detail endpoints have stable cursors.
 */
final class ArrayInjectionAuditStore implements InjectionAuditStore
{
    /** @var list<InjectionAttempt> */
    private array $attempts = [];

    private int $nextId = 1;

    public function append(InjectionAttempt $attempt): void
    {
        $this->attempts[] = new InjectionAttempt(
            $attempt->prompt,
            $attempt->blocked,
            $attempt->ruleId,
            $attempt->principalId,
            $attempt->occurredAt,
            $attempt->rulesetVersion,
            $attempt->erroredRuleIds,
            $attempt->matchedSpan,
            $this->nextId++,
        );
    }

    public function recent(int $limit = 50): array
    {
        return array_slice(array_reverse($this->attempts), 0, max(0, $limit));
    }

    public function query(AuditQueryFilters $filters): AuditPage
    {
        $rows = array_values(array_filter(
            array_reverse($this->attempts), // newest first
            fn (InjectionAttempt $a): bool => $this->matches($a, $filters),
        ));

        if ($filters->cursor !== null) {
            $rows = array_values(array_filter($rows, static fn (InjectionAttempt $a): bool => ($a->id ?? 0) < $filters->cursor));
        }

        $page = array_slice($rows, 0, $filters->limit);
        $hasMore = count($rows) > $filters->limit;
        $last = $page === [] ? null : $page[count($page) - 1]->id;

        return new AuditPage($page, $hasMore ? $last : null);
    }

    public function find(int $id): ?InjectionAttempt
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->id === $id) {
                return $attempt;
            }
        }

        return null;
    }

    public function trend(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $utc = new DateTimeZone('UTC');
        /** @var array<string,array{date:string,total:int,blocked:int,allowed:int}> $buckets */
        $buckets = [];

        foreach ($this->attempts as $a) {
            if ($a->occurredAt < $since || $a->occurredAt > $until) {
                continue;
            }

            $day = $a->occurredAt->setTimezone($utc)->format('Y-m-d');
            $bucket = $buckets[$day] ?? ['date' => $day, 'total' => 0, 'blocked' => 0, 'allowed' => 0];
            $bucket['total']++;
            $a->blocked ? $bucket['blocked']++ : $bucket['allowed']++;
            $buckets[$day] = $bucket;
        }

        ksort($buckets);

        return array_values($buckets);
    }

    private function matches(InjectionAttempt $a, AuditQueryFilters $f): bool
    {
        if ($f->blocked !== null && $a->blocked !== $f->blocked) {
            return false;
        }
        if ($f->ruleId !== null && $a->ruleId !== $f->ruleId) {
            return false;
        }
        if ($f->principalId !== null && $a->principalId !== $f->principalId) {
            return false;
        }
        if ($f->search !== null && ! str_contains($a->prompt, $f->search)) {
            return false;
        }
        if ($f->from !== null && $a->occurredAt < $f->from) {
            return false;
        }
        if ($f->to !== null && $a->occurredAt > $f->to) {
            return false;
        }

        return true;
    }
}
