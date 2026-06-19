<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit\Output;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\AiGuardrails\Output\ArrayOutputStatStore;
use Padosoft\AiGuardrails\Output\OutputStatKind;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the nullable detector parameter on ArrayOutputStatStore.
 *
 * Validates:
 * - record(..., $detector) is stored and grouped by byDetector()
 * - record() without detector (null) is backward-compatible
 * - byDetector() only returns non-null-detector pii_redaction rows
 * - time-window filtering still applies
 */
final class OutputStatStoreDetectorTest extends TestCase
{
    public function test_by_detector_groups_pii_rows_by_detector_name(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::PiiRedaction, 1, 'email');
        $store->record(OutputStatKind::PiiRedaction, 1, 'email');
        $store->record(OutputStatKind::PiiRedaction, 1, 'iban');

        self::assertSame(['email' => 2, 'iban' => 1], $store->byDetector());
    }

    public function test_by_detector_excludes_null_detector_rows(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::PiiRedaction, 3);        // no detector → should not appear
        $store->record(OutputStatKind::PiiRedaction, 1, 'phone');

        self::assertSame(['phone' => 1], $store->byDetector());
    }

    public function test_by_detector_returns_empty_when_no_pii_rows(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::HtmlStripped);

        self::assertSame([], $store->byDetector());
    }

    public function test_by_detector_ignores_non_pii_kinds(): void
    {
        $store = new ArrayOutputStatStore;
        // Only PiiRedaction rows with a non-null detector should appear in byDetector()
        $store->record(OutputStatKind::HtmlStripped, 1, 'email');

        self::assertSame([], $store->byDetector());
    }

    public function test_totals_still_sums_all_pii_rows_including_null_detector(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::PiiRedaction, 3, 'email');
        $store->record(OutputStatKind::PiiRedaction, 2);

        $totals = $store->totals();
        self::assertSame(5, $totals[OutputStatKind::PiiRedaction->value]);
    }

    public function test_record_without_detector_stays_backward_compatible_with_totals(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::HtmlStripped);
        $store->record(OutputStatKind::PiiRedaction, 2);

        $totals = $store->totals();
        self::assertSame(1, $totals[OutputStatKind::HtmlStripped->value]);
        self::assertSame(2, $totals[OutputStatKind::PiiRedaction->value]);
        self::assertSame(3, $store->count());
    }

    public function test_by_detector_respects_time_window(): void
    {
        $store = new ArrayOutputStatStore;
        $store->record(OutputStatKind::PiiRedaction, 1, 'email'); // recorded "now"

        $utc = new DateTimeZone('UTC');
        // Window entirely in the past → should exclude the just-recorded row
        $past = new DateTimeImmutable('2000-01-01', $utc);
        self::assertSame([], $store->byDetector(null, $past));

        // Window including now → should include it
        $future = new DateTimeImmutable('2999-01-01', $utc);
        self::assertSame(['email' => 1], $store->byDetector(null, $future));
    }
}
