<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Firewall\ArrayFirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\FirewallQueryFilters;
use Padosoft\AiGuardrails\Firewall\FirewallRejection;
use PHPUnit\Framework\TestCase;

final class ArrayFirewallRejectionStoreTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private function rejection(string $tool, ?string $principal, string $time): FirewallRejection
    {
        return new FirewallRejection($tool, $principal, ['x' => 'unknown argument'], $this->at($time));
    }

    public function test_record_assigns_sequential_ids_and_counts(): void
    {
        $store = new ArrayFirewallRejectionStore;
        $store->record($this->rejection('refund tool', '7', '2026-01-01 00:00:00'));
        $store->record($this->rejection('delete tool', '8', '2026-01-02 00:00:00'));

        self::assertSame(2, $store->count());

        $page = $store->query(new FirewallQueryFilters);
        self::assertSame([2, 1], array_map(static fn ($r) => $r->id, $page->items)); // newest first
        self::assertNull($page->nextCursor);
    }

    public function test_query_filters_by_principal(): void
    {
        $store = new ArrayFirewallRejectionStore;
        $store->record($this->rejection('a', '7', '2026-01-01 00:00:00'));
        $store->record($this->rejection('b', '8', '2026-01-02 00:00:00'));

        $page = $store->query(new FirewallQueryFilters(principalId: '8'));

        self::assertCount(1, $page->items);
        self::assertSame('b', $page->items[0]->toolDescription);
    }

    public function test_query_search_matches_tool_description(): void
    {
        $store = new ArrayFirewallRejectionStore;
        $store->record($this->rejection('refund order tool', '7', '2026-01-01 00:00:00'));
        $store->record($this->rejection('send email tool', '7', '2026-01-02 00:00:00'));

        $page = $store->query(new FirewallQueryFilters(search: 'refund'));

        self::assertCount(1, $page->items);
        self::assertSame('refund order tool', $page->items[0]->toolDescription);
    }

    public function test_query_paginates_with_cursor(): void
    {
        $store = new ArrayFirewallRejectionStore;
        foreach (range(1, 5) as $i) {
            $store->record($this->rejection("tool {$i}", '7', '2026-01-01 00:00:00'));
        }

        $first = $store->query(new FirewallQueryFilters(limit: 2));
        self::assertSame([5, 4], array_map(static fn ($r) => $r->id, $first->items));
        self::assertSame(4, $first->nextCursor);

        $second = $store->query(new FirewallQueryFilters(limit: 2, cursor: $first->nextCursor));
        self::assertSame([3, 2], array_map(static fn ($r) => $r->id, $second->items));

        $third = $store->query(new FirewallQueryFilters(limit: 2, cursor: $second->nextCursor));
        self::assertSame([1], array_map(static fn ($r) => $r->id, $third->items));
        self::assertNull($third->nextCursor);
    }

    private function windowed(): ArrayFirewallRejectionStore
    {
        $store = new ArrayFirewallRejectionStore;
        $store->record($this->rejection('jan1', '7', '2026-01-01 00:00:00'));
        $store->record($this->rejection('jan5', '7', '2026-01-05 00:00:00'));
        $store->record($this->rejection('jan10', '7', '2026-01-10 00:00:00'));

        return $store;
    }

    public function test_query_filters_by_from_bound_inclusive(): void
    {
        // from = Jan 5 → excludes jan1, includes jan5 (boundary) + jan10.
        $page = $this->windowed()->query(new FirewallQueryFilters(from: $this->at('2026-01-05 00:00:00')));

        self::assertSame(['jan10', 'jan5'], array_map(static fn ($r) => $r->toolDescription, $page->items));
    }

    public function test_query_filters_by_to_bound_inclusive(): void
    {
        // to = Jan 5 → includes jan1 + jan5 (boundary), excludes jan10.
        $page = $this->windowed()->query(new FirewallQueryFilters(to: $this->at('2026-01-05 00:00:00')));

        self::assertSame(['jan5', 'jan1'], array_map(static fn ($r) => $r->toolDescription, $page->items));
    }
}
