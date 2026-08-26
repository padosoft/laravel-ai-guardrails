<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Provenance;

use Padosoft\AiGuardrails\Contracts\GatedToolCallStore;

/**
 * In-memory append-only store (tests / demo). Assigns a sequential id on
 * record so the list endpoint has stable keyset cursors.
 */
final class ArrayGatedToolCallStore implements GatedToolCallStore
{
    /** @var list<GatedToolCall> */
    private array $calls = [];

    private int $nextId = 1;

    public function record(GatedToolCall $call): void
    {
        $this->calls[] = new GatedToolCall(
            $call->toolName,
            $call->principalId,
            $call->tiers,
            $call->blocked,
            $call->occurredAt,
            $this->nextId++,
        );
    }

    public function query(GatedToolCallFilters $filters): GatedToolCallPage
    {
        $rows = array_values(array_filter(
            array_reverse($this->calls), // newest first
            fn (GatedToolCall $c): bool => $this->matches($c, $filters),
        ));

        if ($filters->cursor !== null) {
            $rows = array_values(array_filter($rows, static fn (GatedToolCall $c): bool => ($c->id ?? 0) < $filters->cursor));
        }

        $page = array_slice($rows, 0, $filters->limit);
        $hasMore = count($rows) > $filters->limit;
        $last = $page === [] ? null : $page[count($page) - 1]->id;

        return new GatedToolCallPage($page, $hasMore ? $last : null);
    }

    public function count(): int
    {
        return count($this->calls);
    }

    private function matches(GatedToolCall $c, GatedToolCallFilters $f): bool
    {
        if ($f->principalId !== null && $c->principalId !== $f->principalId) {
            return false;
        }
        if ($f->search !== null && ! str_contains($c->toolName, $f->search)) {
            return false;
        }
        if ($f->blocked !== null && $c->blocked !== $f->blocked) {
            return false;
        }
        if ($f->from !== null && $c->occurredAt < $f->from) {
            return false;
        }
        if ($f->to !== null && $c->occurredAt > $f->to) {
            return false;
        }

        return true;
    }
}
