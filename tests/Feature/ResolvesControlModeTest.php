<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\Support\ControlMode;
use Padosoft\AiGuardrails\Support\ResolvesControlMode;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Pins the gate order of ResolvesControlMode: master kill-switch → per-control enabled → mode.
 */
final class ResolvesControlModeTest extends TestCase
{
    private function mode(): ControlMode
    {
        return ResolvesControlMode::for('tool_firewall', 'ai-guardrails.tool_firewall.enabled');
    }

    public function test_master_kill_switch_off_forces_off(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', true);
        $this->app['config']->set('ai-guardrails.modes.tool_firewall', 'enforce');

        self::assertSame(ControlMode::Off, $this->mode());
    }

    public function test_per_control_disabled_forces_off_even_with_an_active_mode(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', true);
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', false);
        $this->app['config']->set('ai-guardrails.modes.tool_firewall', 'monitor');

        self::assertSame(ControlMode::Off, $this->mode());
    }

    public function test_enabled_with_unset_mode_falls_back_to_enforce(): void
    {
        // Master on + control enabled + mode UNSET → enforce (the fallback flag is `true`, not `false`).
        $this->app['config']->set('ai-guardrails.enabled', true);
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', true);
        $this->app['config']->set('ai-guardrails.modes.tool_firewall', null);

        $mode = $this->mode();
        self::assertSame(ControlMode::Enforce, $mode);
        self::assertTrue($mode->enforces());
    }

    public function test_enabled_with_unrecognised_mode_falls_back_to_enforce(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', true);
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', true);
        $this->app['config']->set('ai-guardrails.modes.tool_firewall', 'nonsense');

        self::assertSame(ControlMode::Enforce, $this->mode());
    }

    public function test_explicit_monitor_mode_is_honoured(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', true);
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', true);
        $this->app['config']->set('ai-guardrails.modes.tool_firewall', 'monitor');

        $mode = $this->mode();
        self::assertSame(ControlMode::Monitor, $mode);
        self::assertTrue($mode->observes());
    }
}
