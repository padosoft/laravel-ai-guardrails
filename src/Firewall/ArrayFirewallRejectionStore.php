<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;

/**
 * In-memory append-only firewall-rejection store (tests / default). Assigns a sequential id on
 * record so the list endpoint has stable keyset cursors.
 */
final class ArrayFirewallRejectionStore implements FirewallRejectionStore
{
    /** @var list<FirewallRejection> */
    private array $rejections = [];

    private int $nextId = 1;

    public function record(FirewallRejection $rejection): void
    {
        $this->rejections[] = new FirewallRejection(
            $rejection->toolDescription,
            $rejection->principalId,
            $rejection->violations,
            $rejection->occurredAt,
            $this->nextId++,
        );
    }

    public function query(FirewallQueryFilters $filters): FirewallPage
    {
        $rows = array_values(array_filter(
            array_reverse($this->rejections), // newest first
            fn (FirewallRejection $r): bool => $this->matches($r, $filters),
        ));

        if ($filters->cursor !== null) {
            $rows = array_values(array_filter($rows, static fn (FirewallRejection $r): bool => ($r->id ?? 0) < $filters->cursor));
        }

        $page = array_slice($rows, 0, $filters->limit);
        $hasMore = count($rows) > $filters->limit;
        $last = $page === [] ? null : $page[count($page) - 1]->id;

        return new FirewallPage($page, $hasMore ? $last : null);
    }

    public function count(): int
    {
        return count($this->rejections);
    }

    private function matches(FirewallRejection $r, FirewallQueryFilters $f): bool
    {
        if ($f->principalId !== null && $r->principalId !== $f->principalId) {
            return false;
        }
        if ($f->search !== null && ! str_contains($r->toolDescription, $f->search)) {
            return false;
        }
        if ($f->from !== null && $r->occurredAt < $f->from) {
            return false;
        }
        if ($f->to !== null && $r->occurredAt > $f->to) {
            return false;
        }

        return true;
    }
}
