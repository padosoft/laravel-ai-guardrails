<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Support\IsoDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsoDateParserTest extends TestCase
{
    public function test_parses_a_bare_date_as_utc_midnight(): void
    {
        $dt = IsoDateParser::parseUtc('2026-01-15');

        self::assertNotNull($dt);
        self::assertSame('2026-01-15T00:00:00+00:00', $dt->format(DATE_ATOM));
    }

    public function test_parses_datetime_with_explicit_offset_and_normalises_to_utc(): void
    {
        $dt = IsoDateParser::parseUtc('2026-01-15T05:00:00+05:00');

        self::assertNotNull($dt);
        self::assertSame('2026-01-15T00:00:00+00:00', $dt->format(DATE_ATOM));
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function offsetWithoutSeconds(): array
    {
        return [
            ['2026-01-15T12:00Z', '2026-01-15T12:00:00+00:00'],
            ['2026-01-15T12:00+05:30', '2026-01-15T06:30:00+00:00'],
            ['2026-01-15T12:00:30Z', '2026-01-15T12:00:30+00:00'],
        ];
    }

    #[DataProvider('offsetWithoutSeconds')]
    public function test_accepts_offset_with_or_without_seconds(string $input, string $expected): void
    {
        $dt = IsoDateParser::parseUtc($input);

        self::assertNotNull($dt);
        self::assertSame($expected, $dt->format(DATE_ATOM));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function invalidInputs(): array
    {
        return [
            ['tomorrow'],
            ['+1 day'],
            ['next year'],
            ['garbage'],
            [''],
            ['2026-02-30'], // calendar overflow (Feb has no 30th)
            ['2026-13-01'], // month overflow
            ['2026-00-10'], // month zero
            ['2026-01-32'], // day overflow
            ['2026-01-15T25:00'], // hour overflow
            ['2026-01-15T12:60'], // minute overflow
            ['2026-01-15T12:00:61'], // second overflow
            ['15-01-2026'], // wrong order
            ['2026/01/15'], // wrong separator
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_relative_garbage_and_calendar_overflow(string $input): void
    {
        self::assertNull(IsoDateParser::parseUtc($input));
    }

    public function test_accepts_leap_day_in_a_leap_year(): void
    {
        // 2028 is a leap year — Feb 29 is valid and must NOT be rejected.
        self::assertNotNull(IsoDateParser::parseUtc('2028-02-29'));
    }

    public function test_rejects_leap_day_in_a_non_leap_year(): void
    {
        self::assertNull(IsoDateParser::parseUtc('2027-02-29'));
    }

    public function test_returns_null_for_non_string(): void
    {
        self::assertNull(IsoDateParser::parseUtc(null));
        self::assertNull(IsoDateParser::parseUtc(12345));
    }

    /**
     * The explicit out-of-range guard must reject values the DateTimeImmutable constructor would
     * silently ROLL OVER (24:00 → next day 00:00, 12:00:60 → next minute). These inputs distinguish
     * the guard from the constructor's own validation, so they pin the `> 23`/`> 59` boundaries.
     */
    public function test_rejects_hour_24_that_would_roll_to_the_next_day(): void
    {
        self::assertNull(IsoDateParser::parseUtc('2026-01-15T24:00'));
    }

    public function test_rejects_second_60_that_would_roll_to_the_next_minute(): void
    {
        self::assertNull(IsoDateParser::parseUtc('2026-01-15T12:00:60'));
    }

    public function test_accepts_the_max_valid_time_of_day(): void
    {
        // 23:59:59 is the boundary that MUST be accepted (guards use strict `>`).
        self::assertNotNull(IsoDateParser::parseUtc('2026-01-15T23:59:59'));
    }
}
