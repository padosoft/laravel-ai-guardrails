<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\AiGuardrails;
use Padosoft\AiGuardrails\Firewall\FirewalledTool;
use Padosoft\AiGuardrails\Firewall\PassthroughArgumentScoper;
use Padosoft\AiGuardrails\Firewall\PermissiveToolArgumentValidator;
use Padosoft\AiGuardrails\Hitl\NullApprovalRouter;
use Padosoft\AiGuardrails\Output\NullPiiRedaction;
use Padosoft\AiGuardrails\Output\PassthroughSanitizer;
use Padosoft\AiGuardrails\Screening\NullInjectionScreener;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\Doubles\FakeOwnedTool;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * Pins the AiGuardrails composition facade on its LEGACY-flag code paths (no explicit ControlMode
 * wired — direct construction), which the provider-driven tests don't exercise because the provider
 * always passes an explicit mode. Covers the `?? (flag ? Enforce : Off)` fallbacks and the
 * 'pass'/'deny' fallback resolution.
 */
final class FacadeCompositionTest extends TestCase
{
    private function make(bool $enabled, bool $hitlEnabled, string $fallback = 'deny'): AiGuardrails
    {
        return new AiGuardrails(
            new NullInjectionScreener,
            new PassthroughSanitizer,
            new NullPiiRedaction,
            new PassthroughArgumentScoper,
            new PermissiveToolArgumentValidator,
            new NullApprovalRouter,
            enabled: $enabled,
            hitlEnabled: $hitlEnabled,
            destructiveTools: [],
            hitlFallback: $fallback,
            // firewallMode/hitlMode left null → the legacy boolean flags drive the posture.
        );
    }

    public function test_guard_wraps_when_enabled_with_no_explicit_mode(): void
    {
        // firewallMode null → defaults to Enforce → the tool IS wrapped.
        self::assertInstanceOf(FirewalledTool::class, $this->make(enabled: true, hitlEnabled: false)->guard(new FakeOwnedTool));
    }

    public function test_guard_is_noop_when_master_disabled(): void
    {
        $tool = new FakeOwnedTool;
        self::assertSame($tool, $this->make(enabled: false, hitlEnabled: false)->guard($tool));
    }

    public function test_route_for_approval_gates_when_hitl_flag_on(): void
    {
        // hitlMode null + hitlEnabled true → Enforce → wrapped; NullRouter unavailable + deny → blocks.
        $tool = new FakeDestructiveTool;
        $gated = $this->make(enabled: true, hitlEnabled: true, fallback: 'deny')->routeForApproval($tool, 'refund');

        $result = (string) $gated->handle(new Request(['order_id' => 'A1']));
        self::assertStringContainsString('blocked', $result);
        self::assertFalse($tool->executed);
    }

    public function test_route_for_approval_is_noop_when_hitl_flag_off(): void
    {
        // hitlEnabled false → Off → returns the raw tool (no gating), so it executes directly.
        $tool = new FakeDestructiveTool;
        $gated = $this->make(enabled: true, hitlEnabled: false)->routeForApproval($tool, 'refund');

        self::assertSame($tool, $gated);
        self::assertSame('refunded', (string) $gated->handle(new Request(['order_id' => 'A1'])));
    }

    public function test_fallback_pass_executes_when_approval_unavailable(): void
    {
        // The 'pass' fallback must resolve to 'pass' (not 'deny'): with an unavailable NullRouter the
        // delegate runs. This pins the `=== 'pass' ? 'pass' : 'deny'` resolution.
        $tool = new FakeDestructiveTool;
        $gated = $this->make(enabled: true, hitlEnabled: true, fallback: 'pass')->routeForApproval($tool, 'refund');

        self::assertSame('refunded', (string) $gated->handle(new Request(['order_id' => 'A1'])));
        self::assertTrue($tool->executed);
    }
}
