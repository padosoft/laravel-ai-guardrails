<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Laravel\Ai\Tools\Request as ToolRequest;
use Padosoft\AiGuardrails\Contracts\GatedToolCallStore;
use Padosoft\AiGuardrails\Provenance\ArrayGatedToolCallStore;
use Padosoft\AiGuardrails\Provenance\GatedToolCall;
use Padosoft\AiGuardrails\Provenance\GatedToolCallFilters;
use Padosoft\AiGuardrails\Provenance\GatedToolCallPage;
use Padosoft\AiGuardrails\Provenance\NullGatedToolCallStore;
use Padosoft\AiGuardrails\Provenance\ProvenanceGatedTool;
use Padosoft\AiGuardrails\Provenance\ProvenanceTier;
use Padosoft\AiGuardrails\Provenance\RequestGroundingProvenance;
use Padosoft\AiGuardrails\Support\ControlMode;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * The Control P decision log.
 *
 * It exists for one reason: monitor mode is meant to let an operator size
 * the blast radius on real traffic before enforcing, and until this store
 * the event it emitted had nowhere to be read. So the load-bearing case
 * here is not the blocked row — it is the OBSERVED one.
 */
final class ProvenanceStoreTest extends TestCase
{
    public function test_monitor_records_the_call_it_let_through(): void
    {
        $store = new ArrayGatedToolCallStore;
        $tool = new FakeDestructiveTool;

        $this->gate($tool, $store, ControlMode::Monitor)->handle(new ToolRequest(['order_id' => 'A1']));

        $this->assertTrue($tool->executed, 'Monitor must not block.');

        $rows = $store->query(new GatedToolCallFilters)->items;
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]->blocked, 'The row must say the call RAN — that is the whole point of the rollout view.');
        $this->assertSame(['untrusted_external'], $rows[0]->tiers);
    }

    public function test_enforce_records_the_call_it_refused(): void
    {
        $store = new ArrayGatedToolCallStore;
        $tool = new FakeDestructiveTool;

        $this->gate($tool, $store, ControlMode::Enforce)->handle(new ToolRequest(['order_id' => 'A1']));

        $this->assertFalse($tool->executed);

        $rows = $store->query(new GatedToolCallFilters)->items;
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->blocked);
        $this->assertSame('refund', $rows[0]->toolName);
    }

    public function test_a_call_that_was_never_gated_records_nothing(): void
    {
        // The log is a record of DECISIONS, not of traffic. An admin page
        // listing every tool call would bury the handful that matter.
        $store = new ArrayGatedToolCallStore;

        $provenance = new RequestGroundingProvenance;
        $provenance->record(ProvenanceTier::TrustedInternal);

        (new ProvenanceGatedTool(
            new FakeDestructiveTool,
            $provenance,
            static fn () => 7,
            'refund',
            [ProvenanceTier::UntrustedExternal],
            ControlMode::Enforce,
            null,
            $store,
        ))->handle(new ToolRequest(['order_id' => 'A1']));

        $this->assertSame(0, $store->count());
    }

    public function test_the_row_carries_no_tool_arguments(): void
    {
        // They are the model's, which is to say possibly the attacker's,
        // and this row lands in an admin panel and a SIEM. Asserted rather
        // than trusted to the DTO's shape, because a later "just add the
        // args, it would help debugging" is exactly how this leaks.
        $store = new ArrayGatedToolCallStore;

        $this->gate(new FakeDestructiveTool, $store, ControlMode::Enforce)
            ->handle(new ToolRequest(['order_id' => 'SENSITIVE-A1']));

        $serialized = json_encode($store->query(new GatedToolCallFilters)->items[0]);
        $this->assertStringNotContainsString('SENSITIVE-A1', (string) $serialized);
    }

    public function test_a_store_failure_never_changes_what_the_tool_does(): void
    {
        // Losing a log row is bad. Letting a logging outage turn a gate
        // decision into an exception the model sees is worse.
        $exploding = new class implements GatedToolCallStore
        {
            public function record(GatedToolCall $call): void
            {
                throw new \RuntimeException('the log is on fire');
            }

            public function query(GatedToolCallFilters $filters): GatedToolCallPage
            {
                return new GatedToolCallPage([]);
            }

            public function count(): int
            {
                return 0;
            }
        };

        $tool = new FakeDestructiveTool;
        $result = (string) $this->gate($tool, $exploding, ControlMode::Enforce)->handle(new ToolRequest(['order_id' => 'A1']));

        $this->assertStringContainsString('was not performed', $result);
        $this->assertFalse($tool->executed);
    }

    public function test_the_blocked_filter_separates_refused_from_observed(): void
    {
        $store = new ArrayGatedToolCallStore;
        $at = new DateTimeImmutable('2026-08-26T10:00:00', new DateTimeZone('UTC'));

        $store->record(new GatedToolCall('refund', '7', ['untrusted_external'], true, $at));
        $store->record(new GatedToolCall('send_email', '7', ['untrusted_external'], false, $at));

        $blocked = $store->query(new GatedToolCallFilters(blocked: true))->items;
        $observed = $store->query(new GatedToolCallFilters(blocked: false))->items;

        $this->assertSame(['refund'], array_map(static fn ($c) => $c->toolName, $blocked));
        $this->assertSame(['send_email'], array_map(static fn ($c) => $c->toolName, $observed));
        $this->assertCount(2, $store->query(new GatedToolCallFilters)->items, 'No filter must mean no filter.');
    }

    public function test_an_unparseable_blocked_param_means_no_filter_not_false(): void
    {
        // "no filter" is the safe reading: silently defaulting to false
        // would hide exactly the refused rows an operator opened the page
        // to see.
        $filters = GatedToolCallFilters::fromRequest(Request::create('/provenance?blocked=maybe'));

        $this->assertNull($filters->blocked);
    }

    public function test_the_null_store_is_the_default_and_keeps_nothing(): void
    {
        $this->assertInstanceOf(NullGatedToolCallStore::class, app(GatedToolCallStore::class));

        $store = new NullGatedToolCallStore;
        $store->record(new GatedToolCall('refund', '7', ['untrusted_external'], true, new DateTimeImmutable));

        $this->assertSame(0, $store->count());
        $this->assertSame([], $store->query(new GatedToolCallFilters)->items);
    }

    private function gate(
        FakeDestructiveTool $tool,
        GatedToolCallStore $store,
        ControlMode $mode,
    ): ProvenanceGatedTool {
        $provenance = new RequestGroundingProvenance;
        $provenance->record(ProvenanceTier::UntrustedExternal);

        return new ProvenanceGatedTool(
            $tool,
            $provenance,
            static fn () => 7,
            'refund',
            [ProvenanceTier::UntrustedExternal],
            $mode,
            null,
            $store,
        );
    }
}
