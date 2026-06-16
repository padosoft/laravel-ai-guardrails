<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\SchemaToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\UserScopedArgumentScoper;
use Padosoft\AiGuardrails\Tests\Doubles\FakeOwnedTool;
use Padosoft\AiGuardrails\Tests\TestCase;

final class FirewallBindingsTest extends TestCase
{
    public function test_firewall_collaborators_resolve_from_the_container(): void
    {
        self::assertInstanceOf(UserScopedArgumentScoper::class, $this->resolve(ArgumentScoper::class));
        self::assertInstanceOf(SchemaToolArgumentValidator::class, $this->resolve(ToolArgumentValidator::class));
    }

    public function test_scoper_uses_configured_owner_keys(): void
    {
        $this->app['config']->set('ai-guardrails.tool_firewall.owner_keys', ['tenant_id']);

        $scoped = $this->resolve(ArgumentScoper::class)->scope(['tenant_id' => 'x'], principalId: '7');

        self::assertSame('7', $scoped['tenant_id']);
    }

    public function test_validator_reject_unknown_toggle_is_honored_in_both_states(): void
    {
        $this->app['config']->set('ai-guardrails.tool_firewall.reject_unknown_arguments', false);
        $lenient = $this->resolve(ToolArgumentValidator::class)->validate(new FakeOwnedTool, ['order_id' => 'A1', 'x' => 1]);
        self::assertSame([], $lenient);

        $this->app['config']->set('ai-guardrails.tool_firewall.reject_unknown_arguments', true);
        $this->app->forgetInstance(ToolArgumentValidator::class);
        $strict = $this->resolve(ToolArgumentValidator::class)->validate(new FakeOwnedTool, ['order_id' => 'A1', 'x' => 1]);
        self::assertArrayHasKey('x', $strict);
    }
}
