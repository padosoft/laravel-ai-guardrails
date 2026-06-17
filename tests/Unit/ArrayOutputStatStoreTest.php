<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Output\ArrayOutputStatStore;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use PHPUnit\Framework\TestCase;

final class ArrayOutputStatStoreTest extends TestCase
{
    public function test_record_aggregates_totals_per_kind_and_counts(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::PiiRedaction, 3);

        $totals = $store->totals();
        self::assertSame(2, $totals[OutputStatKind::HtmlStripped->value]);
        self::assertSame(3, $totals[OutputStatKind::PiiRedaction->value]);
        self::assertArrayNotHasKey(OutputStatKind::MarkdownSanitized->value, $totals);
        self::assertSame(5, $store->count());
    }

    public function test_record_ignores_non_positive_counts(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::HtmlStripped, 0);
        $store->record(OutputStatKind::HtmlStripped, -2);

        self::assertSame(0, $store->count());
        self::assertSame([], $store->totals());
    }

    public function test_totals_respects_the_time_window(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::HtmlStripped);

        $utc = new DateTimeZone('UTC');
        // A window entirely in the past excludes the just-recorded (now) event.
        $past = new DateTimeImmutable('2000-01-01 00:00:00', $utc);
        self::assertSame([], $store->totals(null, $past));

        // A window that includes "now" keeps it.
        $future = new DateTimeImmutable('2999-01-01 00:00:00', $utc);
        self::assertSame([OutputStatKind::HtmlStripped->value => 1], $store->totals(null, $future));
    }
}
