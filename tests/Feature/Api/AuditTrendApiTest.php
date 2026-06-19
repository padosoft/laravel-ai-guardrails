<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Feature tests for the observed bucket added to GET /ai-guardrails/api/audit/trend.
 *
 * Three-way split invariant: total === blocked + observed + allowed, where:
 *   blocked  = blocked = true (rule matched AND was blocked)
 *   observed = blocked = false AND rule_id IS NOT NULL (monitor-mode match — rule matched but NOT blocked)
 *   allowed  = rule_id IS NULL (no rule matched at all)
 */
final class AuditTrendApiTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.api.enabled', true);
        $app['config']->set('ai-guardrails.api.middleware', [SubstituteBindings::class]);
        $app['config']->set('ai-guardrails.audit.store', 'array');
    }

    private function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /**
     * Seed one blocked, one observed, one allowed on the same day (2026-03-15).
     */
    private function seedThreeWaySameDay(): void
    {
        $store = $this->app->make(InjectionAuditStore::class);
        $utc = $this->utc();

        // blocked = true, ruleId set → blocked bucket
        $store->append(new InjectionAttempt(
            'ignore previous instructions',
            true,
            'rule_ignore_previous',
            'u1',
            new DateTimeImmutable('2026-03-15 10:00:00', $utc),
        ));

        // blocked = false, ruleId set → observed bucket (monitor-mode match)
        $store->append(new InjectionAttempt(
            'jailbreak attempt monitored',
            false,
            'rule_jailbreak',
            'u2',
            new DateTimeImmutable('2026-03-15 11:00:00', $utc),
        ));

        // blocked = false, ruleId = null → allowed bucket (no rule matched)
        $store->append(new InjectionAttempt(
            'benign question',
            false,
            null,
            'u3',
            new DateTimeImmutable('2026-03-15 12:00:00', $utc),
        ));
    }

    public function test_trend_exposes_observed_bucket(): void
    {
        $this->seedThreeWaySameDay();

        // Use to=2026-03-16 (midnight) so the 10:00–12:00 UTC attempts on 2026-03-15 fall within
        // the inclusive [from, until] window (bare date = midnight, so attempts must be < midnight next day).
        $response = $this->getJson('/ai-guardrails/api/audit/trend?from=2026-03-15&to=2026-03-16')
            ->assertOk()
            ->assertJsonPath('schema', 'ai-guardrails.api.v1.audit-trend');

        $points = $response->json('data.points');
        self::assertCount(1, $points, 'Expected exactly one day in the result');

        $point = $points[0];
        self::assertSame('2026-03-15', $point['date']);
        self::assertSame(3, $point['total'], 'total must be 3');
        self::assertSame(1, $point['blocked'], 'blocked must be 1');
        self::assertSame(1, $point['observed'], 'observed (monitor-mode match) must be 1');
        self::assertSame(1, $point['allowed'], 'allowed (no rule matched) must be 1');
    }

    public function test_trend_backward_compat_keys_present(): void
    {
        $this->seedThreeWaySameDay();

        $response = $this->getJson('/ai-guardrails/api/audit/trend?from=2026-03-15&to=2026-03-16')
            ->assertOk();

        $point = $response->json('data.points.0');
        self::assertArrayHasKey('date', $point, 'date key must be present');
        self::assertArrayHasKey('total', $point, 'total key must be present');
        self::assertArrayHasKey('blocked', $point, 'blocked key must be present');
        self::assertArrayHasKey('allowed', $point, 'allowed key must be present');
        self::assertArrayHasKey('observed', $point, 'observed key must be present');
        self::assertIsString($point['date']);
        self::assertIsInt($point['total']);
        self::assertIsInt($point['blocked']);
        self::assertIsInt($point['allowed']);
        self::assertIsInt($point['observed']);
    }

    public function test_trend_buckets_sum_to_total(): void
    {
        $store = $this->app->make(InjectionAuditStore::class);
        $utc = $this->utc();

        // Seed a varied mix across multiple days
        $attempts = [
            // Day 2026-04-01: 2 blocked, 1 observed, 2 allowed
            new InjectionAttempt('b1', true, 'r1', 'u1', new DateTimeImmutable('2026-04-01 08:00:00', $utc)),
            new InjectionAttempt('b2', true, 'r2', 'u2', new DateTimeImmutable('2026-04-01 09:00:00', $utc)),
            new InjectionAttempt('o1', false, 'r3', 'u3', new DateTimeImmutable('2026-04-01 10:00:00', $utc)),
            new InjectionAttempt('a1', false, null, 'u4', new DateTimeImmutable('2026-04-01 11:00:00', $utc)),
            new InjectionAttempt('a2', false, null, 'u5', new DateTimeImmutable('2026-04-01 12:00:00', $utc)),
            // Day 2026-04-02: 0 blocked, 3 observed, 0 allowed
            new InjectionAttempt('o2', false, 'r1', 'u6', new DateTimeImmutable('2026-04-02 08:00:00', $utc)),
            new InjectionAttempt('o3', false, 'r2', 'u7', new DateTimeImmutable('2026-04-02 09:00:00', $utc)),
            new InjectionAttempt('o4', false, 'r3', 'u8', new DateTimeImmutable('2026-04-02 10:00:00', $utc)),
            // Day 2026-04-03: 1 blocked, 0 observed, 1 allowed
            new InjectionAttempt('b3', true, 'r4', 'u9', new DateTimeImmutable('2026-04-03 07:00:00', $utc)),
            new InjectionAttempt('a3', false, null, 'u10', new DateTimeImmutable('2026-04-03 08:00:00', $utc)),
        ];

        foreach ($attempts as $attempt) {
            $store->append($attempt);
        }

        // Use to=2026-04-04 (midnight) so attempts at 07:00–12:00 UTC across 2026-04-01 to 03 are
        // all within the inclusive window (bare date = midnight, attempts must be before next-day midnight).
        $response = $this->getJson('/ai-guardrails/api/audit/trend?from=2026-04-01&to=2026-04-04')
            ->assertOk();

        $points = $response->json('data.points');
        self::assertCount(3, $points, 'Expected three days in the trend');

        foreach ($points as $point) {
            $sum = $point['blocked'] + $point['observed'] + $point['allowed'];
            self::assertSame(
                $point['total'],
                $sum,
                "Invariant violated for {$point['date']}: total={$point['total']} but blocked+observed+allowed={$sum}"
            );
        }

        // Verify specific expectations for each day
        self::assertSame('2026-04-01', $points[0]['date']);
        self::assertSame(5, $points[0]['total']);
        self::assertSame(2, $points[0]['blocked']);
        self::assertSame(1, $points[0]['observed']);
        self::assertSame(2, $points[0]['allowed']);

        self::assertSame('2026-04-02', $points[1]['date']);
        self::assertSame(3, $points[1]['total']);
        self::assertSame(0, $points[1]['blocked']);
        self::assertSame(3, $points[1]['observed']);
        self::assertSame(0, $points[1]['allowed']);

        self::assertSame('2026-04-03', $points[2]['date']);
        self::assertSame(2, $points[2]['total']);
        self::assertSame(1, $points[2]['blocked']);
        self::assertSame(0, $points[2]['observed']);
        self::assertSame(1, $points[2]['allowed']);
    }

    /**
     * Degenerate row: blocked=true AND rule_id=null.
     *
     * Before the SQL fix, a row with blocked=true AND rule_id=null was counted as BOTH blocked AND
     * allowed (the old SQL `allowed` CASE was just `rule_id IS NULL` with no NOT-blocked guard).
     * After the fix, it counts ONLY as blocked, and the invariant total === blocked + observed +
     * allowed holds.
     *
     * The ArrayInjectionAuditStore (used by this feature test) already short-circuits correctly;
     * this test proves parity with the DB path and documents the expected invariant at the API level.
     */
    public function test_trend_degenerate_row_blocked_true_rule_id_null_counts_only_as_blocked(): void
    {
        $store = $this->app->make(InjectionAuditStore::class);
        $utc = $this->utc();

        // degenerate: blocked=true, ruleId=null — must count as blocked ONLY (not allowed)
        $store->append(new InjectionAttempt('degenerate', true, null, 'u0', new DateTimeImmutable('2026-06-01 10:00:00', $utc)));
        // normal blocked: blocked=true, ruleId set
        $store->append(new InjectionAttempt('normal blocked', true, 'rule_x', 'u1', new DateTimeImmutable('2026-06-01 11:00:00', $utc)));
        // observed: blocked=false, ruleId set
        $store->append(new InjectionAttempt('observed', false, 'rule_y', 'u2', new DateTimeImmutable('2026-06-01 12:00:00', $utc)));
        // allowed: blocked=false, ruleId=null
        $store->append(new InjectionAttempt('allowed', false, null, 'u3', new DateTimeImmutable('2026-06-01 13:00:00', $utc)));

        $response = $this->getJson('/ai-guardrails/api/audit/trend?from=2026-06-01&to=2026-06-02')
            ->assertOk();

        $points = $response->json('data.points');
        self::assertCount(1, $points, 'Expected exactly one day in the result');

        $point = $points[0];
        self::assertSame('2026-06-01', $point['date']);
        self::assertSame(4, $point['total'], 'total must be 4');
        self::assertSame(2, $point['blocked'], 'degenerate + normal blocked = 2 (degenerate must NOT leak into allowed)');
        self::assertSame(1, $point['observed'], 'observed must be 1');
        self::assertSame(1, $point['allowed'], 'allowed must be 1');
        self::assertSame(
            $point['total'],
            $point['blocked'] + $point['observed'] + $point['allowed'],
            'Invariant: total === blocked + observed + allowed',
        );
    }
}
