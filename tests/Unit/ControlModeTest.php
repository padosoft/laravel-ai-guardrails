<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Support\ControlMode;
use PHPUnit\Framework\TestCase;

final class ControlModeTest extends TestCase
{
    public function test_capability_predicates(): void
    {
        self::assertTrue(ControlMode::Enforce->isActive());
        self::assertTrue(ControlMode::Enforce->enforces());
        self::assertFalse(ControlMode::Enforce->observes());

        self::assertTrue(ControlMode::Monitor->isActive());
        self::assertFalse(ControlMode::Monitor->enforces());
        self::assertTrue(ControlMode::Monitor->observes());

        self::assertFalse(ControlMode::Off->isActive());
        self::assertFalse(ControlMode::Off->enforces());
        self::assertFalse(ControlMode::Off->observes());
    }

    public function test_resolve_parses_known_modes_case_insensitively(): void
    {
        self::assertSame(ControlMode::Enforce, ControlMode::resolve('enforce', true));
        self::assertSame(ControlMode::Monitor, ControlMode::resolve('MONITOR', true));
        self::assertSame(ControlMode::Off, ControlMode::resolve(' off ', true));
    }

    public function test_resolve_falls_back_to_the_enabled_flag_for_unknown_or_non_string(): void
    {
        self::assertSame(ControlMode::Enforce, ControlMode::resolve('garbage', true));
        self::assertSame(ControlMode::Off, ControlMode::resolve('garbage', false));
        self::assertSame(ControlMode::Enforce, ControlMode::resolve(null, true));
        self::assertSame(ControlMode::Off, ControlMode::resolve(null, false));
    }
}
