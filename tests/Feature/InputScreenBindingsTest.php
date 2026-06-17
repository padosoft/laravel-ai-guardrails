<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrailsServiceProvider;
use Padosoft\AiGuardrails\Audit\ArrayInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\DatabaseInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\NullInjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionScreener;
use Padosoft\AiGuardrails\Contracts\PromptNormalizer;
use Padosoft\AiGuardrails\Screening\GuardrailInputMiddleware;
use Padosoft\AiGuardrails\Screening\NullInjectionScreener;
use Padosoft\AiGuardrails\Screening\NullPromptNormalizer;
use Padosoft\AiGuardrails\Screening\PatternInjectionScreener;
use Padosoft\AiGuardrails\Screening\UnicodePromptNormalizer;
use Padosoft\AiGuardrails\Tests\TestCase;

final class InputScreenBindingsTest extends TestCase
{
    private function reregister(): void
    {
        $this->app->forgetInstance(InjectionScreener::class);
        $this->app->forgetInstance(InjectionAuditStore::class);
        (new AiGuardrailsServiceProvider($this->app))->register();
    }

    public function test_pattern_screener_bound_when_input_screen_enabled(): void
    {
        self::assertInstanceOf(PatternInjectionScreener::class, $this->resolve(InjectionScreener::class));
        self::assertInstanceOf(GuardrailInputMiddleware::class, $this->resolve(GuardrailInputMiddleware::class));
    }

    public function test_null_screener_when_input_screen_disabled(): void
    {
        $this->app['config']->set('ai-guardrails.input_screen.enabled', false);
        $this->reregister();

        self::assertInstanceOf(NullInjectionScreener::class, $this->resolve(InjectionScreener::class));
    }

    public function test_master_kill_switch_off_degrades_screener(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.input_screen.enabled', true);
        $this->reregister();

        self::assertInstanceOf(NullInjectionScreener::class, $this->resolve(InjectionScreener::class));
    }

    public function test_audit_store_factory_resolves_per_config(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'array');
        $this->reregister();
        self::assertInstanceOf(ArrayInjectionAuditStore::class, $this->resolve(InjectionAuditStore::class));

        $this->app['config']->set('ai-guardrails.audit.store', 'database');
        $this->reregister();
        self::assertInstanceOf(DatabaseInjectionAuditStore::class, $this->resolve(InjectionAuditStore::class));

        $this->app['config']->set('ai-guardrails.audit.store', 'null');
        $this->reregister();
        self::assertInstanceOf(NullInjectionAuditStore::class, $this->resolve(InjectionAuditStore::class));
    }

    public function test_master_kill_switch_off_forces_null_audit_store(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.audit.store', 'database'); // would persist if not for master off
        $this->reregister();

        self::assertInstanceOf(NullInjectionAuditStore::class, $this->resolve(InjectionAuditStore::class));
    }

    public function test_prompt_normalizer_binding_respects_config(): void
    {
        self::assertInstanceOf(
            UnicodePromptNormalizer::class,
            $this->resolve(PromptNormalizer::class),
        );

        $this->app['config']->set('ai-guardrails.normalization.enabled', false);
        $this->app->forgetInstance(PromptNormalizer::class);
        (new AiGuardrailsServiceProvider($this->app))->register();

        self::assertInstanceOf(
            NullPromptNormalizer::class,
            $this->resolve(PromptNormalizer::class),
        );
    }
}
