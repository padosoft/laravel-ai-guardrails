<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Padosoft\AiGuardrails\Firewall\DatabaseFirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\FirewallQueryFilters;
use Padosoft\AiGuardrails\Firewall\FirewallRejection;
use Padosoft\AiGuardrails\Firewall\FirewallRejectionRecord;
use Padosoft\AiGuardrails\Tests\TestCase;

final class DatabaseFirewallRejectionStoreTest extends TestCase
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

        $migration = require __DIR__.'/../../database/migrations/create_ai_guardrails_firewall_rejections_table.php.stub';
        $migration->up();
    }

    private function store(): DatabaseFirewallRejectionStore
    {
        return new DatabaseFirewallRejectionStore(null, 'ai_guardrails_firewall_rejections');
    }

    private function rejection(string $tool, ?string $principal, string $time): FirewallRejection
    {
        return new FirewallRejection($tool, $principal, ['evil' => 'unknown argument'], new DateTimeImmutable($time, new DateTimeZone('UTC')));
    }

    public function test_record_then_query_round_trips_with_violations(): void
    {
        $store = $this->store();
        $store->record($this->rejection('refund tool', '42', '2026-01-01 10:00:00'));

        $page = $store->query(new FirewallQueryFilters);

        self::assertCount(1, $page->items);
        self::assertSame('refund tool', $page->items[0]->toolDescription);
        self::assertSame('42', $page->items[0]->principalId);
        self::assertSame(['evil' => 'unknown argument'], $page->items[0]->violations);
        self::assertNotNull($page->items[0]->id);
        self::assertSame(1, $store->count());
    }

    public function test_query_filters_and_keyset_paginates(): void
    {
        $store = $this->store();
        foreach (range(1, 4) as $i) {
            $store->record($this->rejection("tool {$i}", 'p'.$i, "2026-01-0{$i} 00:00:00"));
        }

        $page = $store->query(new FirewallQueryFilters(principalId: 'p2'));
        self::assertCount(1, $page->items);
        self::assertSame('tool 2', $page->items[0]->toolDescription);

        $first = $store->query(new FirewallQueryFilters(limit: 2));
        self::assertCount(2, $first->items);
        self::assertSame('tool 4', $first->items[0]->toolDescription);
        self::assertNotNull($first->nextCursor);

        $next = $store->query(new FirewallQueryFilters(limit: 2, cursor: $first->nextCursor));
        self::assertSame(['tool 2', 'tool 1'], array_map(static fn ($r) => $r->toolDescription, $next->items));
        self::assertNull($next->nextCursor);
    }

    public function test_query_search_treats_like_metacharacters_literally(): void
    {
        $store = $this->store();
        $store->record($this->rejection('100% refund tool', '7', '2026-01-01 00:00:00'));
        $store->record($this->rejection('email tool', '7', '2026-01-02 00:00:00'));

        // A bare '%' must match only the row containing a literal '%', not act as a wildcard.
        $page = $store->query(new FirewallQueryFilters(search: '%'));

        self::assertCount(1, $page->items);
        self::assertSame('100% refund tool', $page->items[0]->toolDescription);
    }

    public function test_record_is_append_only_update_throws(): void
    {
        $this->store()->record($this->rejection('x', null, '2026-01-01 00:00:00'));

        $record = FirewallRejectionRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->update(['tool_description' => 'tampered']);
    }

    public function test_record_is_append_only_delete_throws(): void
    {
        $this->store()->record($this->rejection('x', null, '2026-01-01 00:00:00'));

        $record = FirewallRejectionRecord::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_builder_mass_update_throws(): void
    {
        $this->store()->record($this->rejection('x', null, '2026-01-01 00:00:00'));

        $this->expectException(LogicException::class);
        FirewallRejectionRecord::query()->update(['tool_description' => 'tampered']);
    }

    public function test_builder_truncate_throws(): void
    {
        $this->store()->record($this->rejection('x', null, '2026-01-01 00:00:00'));

        $this->expectException(LogicException::class);
        FirewallRejectionRecord::query()->truncate();
    }
}
