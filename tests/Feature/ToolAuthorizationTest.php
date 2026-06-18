<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate as GateFacade;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\AiGuardrails;
use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Contracts\ArgumentScoper;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;
use Padosoft\AiGuardrails\Contracts\ToolAuthorizer;
use Padosoft\AiGuardrails\Exceptions\ToolNotAuthorized;
use Padosoft\AiGuardrails\Firewall\AllowAllToolAuthorizer;
use Padosoft\AiGuardrails\Firewall\AuthorizedTool;
use Padosoft\AiGuardrails\Firewall\FirewalledTool;
use Padosoft\AiGuardrails\Firewall\GateToolAuthorizer;
use Padosoft\AiGuardrails\Tests\Doubles\FakeOwnedTool;
use Padosoft\AiGuardrails\Tests\TestCase;

final class ToolAuthorizationTest extends TestCase
{
    private const ABILITY = 'ai-guardrails:use-tool';

    private function user(): Authenticatable
    {
        return new class implements Authenticatable
        {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 1;
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return '';
            }
        };
    }

    private function gateAuthorizer(): GateToolAuthorizer
    {
        return new GateToolAuthorizer($this->app->make(Gate::class), self::ABILITY);
    }

    // ---- AuthorizedTool decorator --------------------------------------------------------------

    public function test_allows_when_authorizer_grants(): void
    {
        $tool = new FakeOwnedTool;
        $gated = new AuthorizedTool($tool, new AllowAllToolAuthorizer, FakeOwnedTool::class);

        self::assertSame('ok', (string) $gated->handle(new Request(['order_id' => 'A1'])));
        self::assertNotNull($tool->received);
    }

    public function test_throws_and_does_not_run_the_tool_when_denied(): void
    {
        $deny = new class implements ToolAuthorizer
        {
            public function authorize(string $toolIdentifier): bool
            {
                return false;
            }
        };
        $tool = new FakeOwnedTool;
        $gated = new AuthorizedTool($tool, $deny, FakeOwnedTool::class);

        try {
            $gated->handle(new Request(['order_id' => 'A1']));
            self::fail('Expected ToolNotAuthorized.');
        } catch (ToolNotAuthorized $e) {
            self::assertSame(FakeOwnedTool::class, $e->toolIdentifier);
            self::assertNull($tool->received); // delegate never ran
        }
    }

    // ---- GateToolAuthorizer (fail-closed) ------------------------------------------------------

    public function test_gate_allows_the_authorized_class(): void
    {
        GateFacade::define(self::ABILITY, static fn ($user, string $cls): bool => str_contains($cls, 'FakeOwnedTool'));
        $this->actingAs($this->user());

        self::assertTrue($this->gateAuthorizer()->authorize(FakeOwnedTool::class));
        self::assertFalse($this->gateAuthorizer()->authorize('Some\\Other\\Tool'));
    }

    public function test_gate_fails_closed_when_ability_is_undefined(): void
    {
        $this->actingAs($this->user());

        // No Gate::define for the ability → Gate::allows returns false → denied.
        self::assertFalse($this->gateAuthorizer()->authorize(FakeOwnedTool::class));
    }

    public function test_gate_fails_closed_when_the_policy_throws(): void
    {
        GateFacade::define(self::ABILITY, static function (): bool {
            throw new \RuntimeException('policy boom');
        });
        $this->actingAs($this->user());

        self::assertFalse($this->gateAuthorizer()->authorize(FakeOwnedTool::class));
    }

    public function test_gate_fails_closed_when_unauthenticated(): void
    {
        GateFacade::define(self::ABILITY, static fn ($user, string $cls): bool => true);
        // deliberately no actingAs() — no authenticated user

        self::assertFalse($this->gateAuthorizer()->authorize(FakeOwnedTool::class));
    }

    // ---- guard() composition (both-states) -----------------------------------------------------

    private function rebuild(): AiGuardrails
    {
        foreach ([InjectionScreener::class, ArgumentScoper::class, ToolArgumentValidator::class, ApprovalRouter::class, ToolAuthorizer::class, AiGuardrails::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        (new AiGuardrailsServiceProvider($this->app))->register();

        return $this->resolve(AiGuardrails::class);
    }

    public function test_guard_wraps_with_authorization_when_enabled_and_denies(): void
    {
        $this->app['config']->set('ai-guardrails.tool_authorization.enabled', true);
        GateFacade::define(self::ABILITY, static fn (): bool => false); // deny everything
        $this->actingAs($this->user());

        $guarded = $this->rebuild()->guard(new FakeOwnedTool);
        self::assertInstanceOf(AuthorizedTool::class, $guarded);

        $this->expectException(ToolNotAuthorized::class);
        $guarded->handle(new Request(['order_id' => 'A1']));
    }

    public function test_guard_is_plain_firewall_when_authorization_disabled(): void
    {
        $this->app['config']->set('ai-guardrails.tool_authorization.enabled', false);

        $guarded = $this->rebuild()->guard(new FakeOwnedTool);
        self::assertInstanceOf(FirewalledTool::class, $guarded);
    }
}
