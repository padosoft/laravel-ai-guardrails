<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\FirewallRejectionStore;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\ArrayFirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\NullFirewallRejectionStore;
use Padosoft\AiGuardrails\Firewall\PassthroughArgumentScoper;
use Padosoft\AiGuardrails\Firewall\PermissiveToolArgumentValidator;
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

    public function test_firewall_enabled_false_binds_passthrough_null_objects(): void
    {
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', false);
        $this->app->forgetInstance(ArgumentScoper::class);
        $this->app->forgetInstance(ToolArgumentValidator::class);

        // Re-register to pick up the new config value.
        (new AiGuardrailsServiceProvider($this->app))->register();

        self::assertInstanceOf(PassthroughArgumentScoper::class, $this->resolve(ArgumentScoper::class));
        self::assertInstanceOf(PermissiveToolArgumentValidator::class, $this->resolve(ToolArgumentValidator::class));
    }

    public function test_firewall_enabled_true_binds_real_implementations(): void
    {
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', true);
        $this->app->forgetInstance(ArgumentScoper::class);
        $this->app->forgetInstance(ToolArgumentValidator::class);

        (new AiGuardrailsServiceProvider($this->app))->register();

        self::assertInstanceOf(UserScopedArgumentScoper::class, $this->resolve(ArgumentScoper::class));
        self::assertInstanceOf(SchemaToolArgumentValidator::class, $this->resolve(ToolArgumentValidator::class));
    }

    public function test_master_kill_switch_off_degrades_firewall_to_passthrough(): void
    {
        // Master off must degrade the firewall even when tool_firewall.enabled is true.
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.tool_firewall.enabled', true);
        $this->app->forgetInstance(ArgumentScoper::class);
        $this->app->forgetInstance(ToolArgumentValidator::class);

        (new AiGuardrailsServiceProvider($this->app))->register();

        self::assertInstanceOf(PassthroughArgumentScoper::class, $this->resolve(ArgumentScoper::class));
        self::assertInstanceOf(PermissiveToolArgumentValidator::class, $this->resolve(ToolArgumentValidator::class));
    }

    public function test_firewall_rejection_store_resolves_per_config(): void
    {
        // Default: null store (no persistence).
        self::assertInstanceOf(NullFirewallRejectionStore::class, $this->resolve(FirewallRejectionStore::class));

        $this->app['config']->set('ai-guardrails.firewall_log.store', 'array');
        $this->app->forgetInstance(FirewallRejectionStore::class);
        self::assertInstanceOf(ArrayFirewallRejectionStore::class, $this->resolve(FirewallRejectionStore::class));
    }

    public function test_firewall_rejection_store_is_null_when_master_off(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.firewall_log.store', 'array');
        $this->app->forgetInstance(FirewallRejectionStore::class);

        self::assertInstanceOf(NullFirewallRejectionStore::class, $this->resolve(FirewallRejectionStore::class));
    }
}
