<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Hitl\FlowApprovalRouter;
use Padosoft\AiGuardrails\Hitl\NullApprovalRouter;
use Padosoft\AiGuardrails\Tests\TestCase;

final class HitlBindingsTest extends TestCase
{
    private function reregister(): void
    {
        $this->app->forgetInstance(ApprovalRouter::class);
        (new AiGuardrailsServiceProvider($this->app))->register();
    }

    public function test_null_router_when_hitl_disabled_by_default(): void
    {
        // Default config: hitl.enabled = false.
        self::assertInstanceOf(NullApprovalRouter::class, $this->resolve(ApprovalRouter::class));
    }

    public function test_flow_router_when_hitl_enabled_and_flow_present(): void
    {
        $this->app['config']->set('ai-guardrails.hitl.enabled', true);
        $this->reregister();

        self::assertInstanceOf(FlowApprovalRouter::class, $this->resolve(ApprovalRouter::class));
    }

    public function test_master_kill_switch_off_forces_null_router(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.hitl.enabled', true);
        $this->reregister();

        self::assertInstanceOf(NullApprovalRouter::class, $this->resolve(ApprovalRouter::class));
    }
}
