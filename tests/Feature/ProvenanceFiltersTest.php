<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Padosoft\AiGuardrails\Provenance\ArrayGatedToolCallStore;
use Padosoft\AiGuardrails\Provenance\GatedToolCall;
use Padosoft\AiGuardrails\Provenance\GatedToolCallFilters;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * The read path of the Control P log: request parsing, the filter arms, and
 * keyset pagination.
 *
 * These are the bounds an operator's browser hits directly, so "it works on
 * the happy path" is not enough — a limit clamp that silently lets a client
 * ask for 100k rows, or a `from` filter that ignores its argument, both
 * look fine until someone relies on them.
 */
final class ProvenanceFiltersTest extends TestCase
{
    private function filters(string $query): GatedToolCallFilters
    {
        return GatedToolCallFilters::fromRequest(Request::create('/provenance?'.$query));
    }

    public function test_limit_defaults_to_fifty(): void
    {
        $this->assertSame(50, $this->filters('')->limit);
    }

    public function test_limit_is_clamped_at_both_ends(): void
    {
        // Upper bound so one request cannot ask for the whole table; lower
        // bound so `limit=0` is a page of one rather than an empty page the
        // caller reads as "no data".
        $this->assertSame(200, $this->filters('limit=100000')->limit);
        $this->assertSame(1, $this->filters('limit=0')->limit);
        $this->assertSame(75, $this->filters('limit=75')->limit);
    }

    public function test_a_non_numeric_limit_falls_back_to_the_default(): void
    {
        $this->assertSame(50, $this->filters('limit=all')->limit);
        $this->assertSame(50, $this->filters('limit=-5')->limit);
    }

    public function test_every_accepted_spelling_of_blocked(): void
    {
        $this->assertTrue($this->filters('blocked=1')->blocked);
        $this->assertTrue($this->filters('blocked=true')->blocked);
        $this->assertFalse($this->filters('blocked=0')->blocked);
        $this->assertFalse($this->filters('blocked=false')->blocked);
        $this->assertNull($this->filters('')->blocked);
    }

    public function test_an_empty_string_param_is_absent_not_a_filter(): void
    {
        // `?principal_id=` is a browser sending a cleared input, not a
        // request for rows whose principal is the empty string.
        $this->assertNull($this->filters('principal_id=&q=')->principalId);
        $this->assertNull($this->filters('principal_id=&q=')->search);
    }

    public function test_a_positive_cursor_is_kept_and_a_zero_like_one_is_dropped(): void
    {
        $this->assertSame(12, $this->filters('cursor=12')->cursor);
        $this->assertNull($this->filters('cursor=0')->cursor);
        $this->assertNull($this->filters('cursor=-1')->cursor);
    }

    public function test_the_time_window_filters_are_applied(): void
    {
        $store = new ArrayGatedToolCallStore;
        $utc = new DateTimeZone('UTC');
        $store->record(new GatedToolCall('old', 'u1', ['untrusted_external'], true, new DateTimeImmutable('2026-01-01 10:00:00', $utc)));
        $store->record(new GatedToolCall('new', 'u1', ['untrusted_external'], true, new DateTimeImmutable('2026-06-01 10:00:00', $utc)));

        $after = $store->query(new GatedToolCallFilters(from: new DateTimeImmutable('2026-03-01 00:00:00', $utc)))->items;
        $before = $store->query(new GatedToolCallFilters(to: new DateTimeImmutable('2026-03-01 00:00:00', $utc)))->items;

        $this->assertSame(['new'], array_map(static fn ($c) => $c->toolName, $after));
        $this->assertSame(['old'], array_map(static fn ($c) => $c->toolName, $before));
    }

    public function test_pagination_returns_a_cursor_only_while_more_rows_remain(): void
    {
        $store = new ArrayGatedToolCallStore;
        $utc = new DateTimeZone('UTC');
        foreach (range(1, 5) as $n) {
            $store->record(new GatedToolCall("tool_{$n}", 'u1', ['untrusted_external'], true, new DateTimeImmutable("2026-01-0{$n} 10:00:00", $utc)));
        }

        $first = $store->query(new GatedToolCallFilters(limit: 2));
        $this->assertCount(2, $first->items);
        $this->assertNotNull($first->nextCursor, 'Three rows remain, so a cursor must be offered.');

        // Newest-first: ids 5,4 then 3,2 then 1.
        $this->assertSame(['tool_5', 'tool_4'], array_map(static fn ($c) => $c->toolName, $first->items));

        $second = $store->query(new GatedToolCallFilters(limit: 2, cursor: $first->nextCursor));
        $this->assertSame(['tool_3', 'tool_2'], array_map(static fn ($c) => $c->toolName, $second->items));

        $last = $store->query(new GatedToolCallFilters(limit: 2, cursor: $second->nextCursor));
        $this->assertSame(['tool_1'], array_map(static fn ($c) => $c->toolName, $last->items));
        $this->assertNull($last->nextCursor, 'The final page must not offer a cursor to nowhere.');
    }

    public function test_count_reports_every_recorded_decision(): void
    {
        $store = new ArrayGatedToolCallStore;
        $this->assertSame(0, $store->count());

        $store->record(new GatedToolCall('refund', 'u1', ['untrusted_external'], true, new DateTimeImmutable));
        $store->record(new GatedToolCall('refund', 'u1', ['untrusted_external'], false, new DateTimeImmutable));

        $this->assertSame(2, $store->count());
    }

    public function test_search_matches_a_substring_of_the_tool_name(): void
    {
        $store = new ArrayGatedToolCallStore;
        $store->record(new GatedToolCall('send_email', 'u1', ['untrusted_external'], true, new DateTimeImmutable));
        $store->record(new GatedToolCall('refund', 'u1', ['untrusted_external'], true, new DateTimeImmutable));

        $hits = $store->query(new GatedToolCallFilters(search: 'email'))->items;

        $this->assertSame(['send_email'], array_map(static fn ($c) => $c->toolName, $hits));
    }
}
