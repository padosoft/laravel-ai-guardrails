<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Padosoft\AiGuardrails\Audit\AuditQueryFilters;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Audit\InjectionAuditRecord;
use Padosoft\AiGuardrails\Tests\TestCase;

final class DatabaseInjectionAuditStoreTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Build the table from the published migration stub so the schema is the real one.
        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_injection_audit_table.php.stub';
        $migration->up();
    }

    private function store(): DatabaseInjectionAuditStore
    {
        return new DatabaseInjectionAuditStore(null, 'ai_guardrails_injection_audit');
    }

    public function test_append_then_recent_round_trips_most_recent_first(): void
    {
        $store = $this->store();

        $store->append(new InjectionAttempt('first', false, null, '42', new DateTimeImmutable('2026-01-01 10:00:00'), 'v1'));
        $store->append(new InjectionAttempt('ignore previous', true, 'ignore_previous', '42', new DateTimeImmutable('2026-01-01 10:05:00'), 'v1'));

        $recent = $store->recent(10);

        self::assertCount(2, $recent);
        self::assertSame('ignore previous', $recent[0]->prompt); // most recent first
        self::assertTrue($recent[0]->blocked);
        self::assertSame('ignore_previous', $recent[0]->ruleId);
        self::assertSame('v1', $recent[0]->rulesetVersion);
        self::assertSame('first', $recent[1]->prompt);
        self::assertFalse($recent[1]->blocked);
    }

    public function test_matched_span_and_id_round_trip(): void
    {
        $store = $this->store();
        $store->append(new InjectionAttempt('ignore previous', true, 'ignore_previous', null, new DateTimeImmutable('2026-01-01 10:00:00'), 'v1', [], [7, 15]));

        $recent = $store->recent(1);
        self::assertCount(1, $recent);
        self::assertSame([7, 15], $recent[0]->matchedSpan);
        self::assertNotNull($recent[0]->id);

        $found = $store->find($recent[0]->id);
        self::assertNotNull($found);
        self::assertSame('ignore previous', $found->prompt);
        self::assertSame([7, 15], $found->matchedSpan);
    }

    public function test_find_returns_null_when_absent(): void
    {
        self::assertNull($this->store()->find(404));
    }

    public function test_query_filters_and_keyset_paginates(): void
    {
        $store = $this->store();
        foreach (range(1, 4) as $i) {
            $blocked = $i % 2 === 0;
            $store->append(new InjectionAttempt("p{$i}", $blocked, $blocked ? 'r' : null, 'principal-'.$i, new DateTimeImmutable("2026-01-0{$i} 00:00:00")));
        }

        $blockedPage = $store->query(new AuditQueryFilters(blocked: true));
        self::assertCount(2, $blockedPage->items);
        foreach ($blockedPage->items as $item) {
            self::assertTrue($item->blocked);
        }

        $firstPage = $store->query(new AuditQueryFilters(limit: 2));
        self::assertCount(2, $firstPage->items);
        self::assertNotNull($firstPage->nextCursor);
        self::assertSame('p4', $firstPage->items[0]->prompt); // newest id first

        $nextPage = $store->query(new AuditQueryFilters(limit: 2, cursor: $firstPage->nextCursor));
        self::assertSame(['p2', 'p1'], array_map(static fn ($a) => $a->prompt, $nextPage->items));
        self::assertNull($nextPage->nextCursor);
    }

    public function test_query_search_filters_by_prompt(): void
    {
        $store = $this->store();
        $store->append(new InjectionAttempt('ignore previous instructions', true, 'r', null, new DateTimeImmutable('2026-01-01 00:00:00')));
        $store->append(new InjectionAttempt('benign question', false, null, null, new DateTimeImmutable('2026-01-02 00:00:00')));

        $page = $store->query(new AuditQueryFilters(search: 'ignore'));

        self::assertCount(1, $page->items);
        self::assertSame('ignore previous instructions', $page->items[0]->prompt);
    }

    public function test_query_search_treats_percent_as_literal(): void
    {
        $store = $this->store();
        $store->append(new InjectionAttempt('100% safe', false, null, null, new DateTimeImmutable('2026-01-01 00:00:00')));
        $store->append(new InjectionAttempt('benign question', false, null, null, new DateTimeImmutable('2026-01-02 00:00:00')));

        // A bare '%' should match only rows containing a literal '%', not all rows.
        $page = $store->query(new AuditQueryFilters(search: '%'));

        self::assertCount(1, $page->items);
        self::assertSame('100% safe', $page->items[0]->prompt);
    }

    public function test_query_search_treats_underscore_as_literal(): void
    {
        $store = $this->store();
        $store->append(new InjectionAttempt('snake_case prompt', false, null, null, new DateTimeImmutable('2026-01-01 00:00:00')));
        $store->append(new InjectionAttempt('normal question', false, null, null, new DateTimeImmutable('2026-01-02 00:00:00')));

        // '_' should match only rows containing a literal underscore, not any single character.
        $page = $store->query(new AuditQueryFilters(search: '_'));

        self::assertCount(1, $page->items);
        self::assertSame('snake_case prompt', $page->items[0]->prompt);
    }

    public function test_trend_aggregates_per_day_in_sql(): void
    {
        $store = $this->store();
        $utc = new DateTimeZone('UTC');
        $store->append(new InjectionAttempt('a', true, 'r', null, new DateTimeImmutable('2026-01-01 09:00:00', $utc)));
        $store->append(new InjectionAttempt('b', false, null, null, new DateTimeImmutable('2026-01-01 22:00:00', $utc)));
        $store->append(new InjectionAttempt('c', true, 'r', null, new DateTimeImmutable('2026-01-02 05:00:00', $utc)));

        $trend = $store->trend(
            new DateTimeImmutable('2026-01-01 00:00:00', $utc),
            new DateTimeImmutable('2026-01-03 00:00:00', $utc),
        );

        self::assertSame([
            ['date' => '2026-01-01', 'total' => 2, 'blocked' => 1, 'observed' => 0, 'allowed' => 1],
            ['date' => '2026-01-02', 'total' => 1, 'blocked' => 1, 'observed' => 0, 'allowed' => 0],
        ], $trend);
    }

    public function test_record_is_append_only_update_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $record = InjectionAuditRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->update(['prompt' => 'tampered']);
    }

    public function test_record_is_append_only_delete_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $record = InjectionAuditRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_builder_mass_delete_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $this->expectException(LogicException::class);
        InjectionAuditRecord::query()->delete();
    }

    public function test_builder_mass_update_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $this->expectException(LogicException::class);
        InjectionAuditRecord::query()->update(['prompt' => 'tampered']);
    }

    public function test_builder_touch_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $this->expectException(LogicException::class);
        InjectionAuditRecord::query()->touch('occurred_at');
    }

    public function test_builder_increment_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $this->expectException(LogicException::class);
        InjectionAuditRecord::query()->increment('id');
    }

    public function test_builder_upsert_throws(): void
    {
        $this->expectException(LogicException::class);
        InjectionAuditRecord::query()->upsert([['id' => 1, 'prompt' => 'x', 'blocked' => true, 'occurred_at' => '2026-01-01 00:00:00']], 'id');
    }

    public function test_builder_truncate_throws(): void
    {
        $this->store()->append(new InjectionAttempt('x', true, 'r', null, new DateTimeImmutable));

        $this->expectException(LogicException::class);
        InjectionAuditRecord::query()->truncate();
    }

    /**
     * Degenerate row: blocked=true AND ruleId=null.
     *
     * Before the fix, the SQL `allowed` CASE (`rule_id IS NULL`) had no NOT-blocked guard, so a
     * row with blocked=true AND rule_id=null was counted as BOTH blocked AND allowed, breaking the
     * invariant total === blocked + observed + allowed.
     * After the fix (allowed = NOT blocked AND rule_id IS NULL), the row counts as `blocked` only.
     */
    public function test_trend_degenerate_row_blocked_true_rule_id_null_counts_only_as_blocked(): void
    {
        $store = $this->store();
        $utc = new DateTimeZone('UTC');

        // degenerate: blocked=true, rule_id=null — must count as blocked ONLY
        $store->append(new InjectionAttempt('degenerate', true, null, null, new DateTimeImmutable('2026-05-01 10:00:00', $utc)));
        // normal blocked: blocked=true, ruleId set
        $store->append(new InjectionAttempt('normal blocked', true, 'rule_x', null, new DateTimeImmutable('2026-05-01 11:00:00', $utc)));
        // observed: blocked=false, ruleId set
        $store->append(new InjectionAttempt('observed', false, 'rule_y', null, new DateTimeImmutable('2026-05-01 12:00:00', $utc)));
        // allowed: blocked=false, ruleId=null
        $store->append(new InjectionAttempt('allowed', false, null, null, new DateTimeImmutable('2026-05-01 13:00:00', $utc)));

        $trend = $store->trend(
            new DateTimeImmutable('2026-05-01 00:00:00', $utc),
            new DateTimeImmutable('2026-05-02 00:00:00', $utc),
        );

        self::assertCount(1, $trend);
        $point = $trend[0];
        self::assertSame('2026-05-01', $point['date']);
        self::assertSame(4, $point['total'], 'total must be 4');
        self::assertSame(2, $point['blocked'], 'degenerate + normal blocked = 2');
        self::assertSame(1, $point['observed'], 'observed must be 1');
        self::assertSame(1, $point['allowed'], 'allowed must be 1 (degenerate row must NOT leak into allowed)');
        self::assertSame(
            $point['total'],
            $point['blocked'] + $point['observed'] + $point['allowed'],
            'Invariant: total === blocked + observed + allowed',
        );
    }
}
