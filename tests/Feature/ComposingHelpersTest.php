<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Padosoft\AiGuardrails\AiGuardrails;
use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Firewall\FirewalledTool;
use Padosoft\AiGuardrails\Hitl\ApprovalGatedTool;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\Doubles\FakeOwnedTool;
use Padosoft\AiGuardrails\Tests\TestCase;

final class ComposingHelpersTest extends TestCase
{
    public function test_guard_wraps_a_tool_in_the_firewall(): void
    {
        self::assertInstanceOf(FirewalledTool::class, $this->resolve(AiGuardrails::class)->guard(new FakeOwnedTool));
    }

    public function test_route_for_approval_wraps_a_destructive_tool_when_hitl_enabled(): void
    {
        $this->app['config']->set('ai-guardrails.hitl.enabled', true);
        $this->app->forgetInstance(AiGuardrails::class);
        (new AiGuardrailsServiceProvider($this->app))->register();

        self::assertInstanceOf(
            ApprovalGatedTool::class,
            $this->resolve(AiGuardrails::class)->routeForApproval(new FakeDestructiveTool, 'refund'),
        );
    }

    public function test_route_for_approval_is_a_no_op_when_hitl_disabled(): void
    {
        // Default config: hitl.enabled = false → no gating (otherwise a 'deny' fallback would block).
        $tool = new FakeDestructiveTool;

        self::assertSame($tool, $this->resolve(AiGuardrails::class)->routeForApproval($tool, 'refund'));
    }

    public function test_is_destructive_matches_configured_tools(): void
    {
        $guardrails = $this->resolve(AiGuardrails::class);

        self::assertTrue($guardrails->isDestructive('refund')); // default destructive_tools
        self::assertFalse($guardrails->isDestructive('search_docs'));
    }

    public function test_validate_structured_validates_against_schema(): void
    {
        $guardrails = $this->resolve(AiGuardrails::class);
        $schema = ['action' => (new JsonSchemaTypeFactory)->string()->required()];

        self::assertSame([], $guardrails->validateStructured(['action' => 'refund'], $schema));
        self::assertArrayHasKey('action', $guardrails->validateStructured([], $schema));
    }

    public function test_master_kill_switch_off_returns_unwrapped_tools(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app->forgetInstance(AiGuardrails::class);
        (new AiGuardrailsServiceProvider($this->app))->register();

        $guardrails = $this->resolve(AiGuardrails::class);
        $tool = new FakeOwnedTool;

        self::assertSame($tool, $guardrails->guard($tool));
        self::assertSame($tool, $guardrails->routeForApproval($tool, 'refund'));
    }
}
