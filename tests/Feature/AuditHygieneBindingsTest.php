<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use Padosoft\AiGuardrails\Audit\HygienicInjectionAuditStore;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Audit\NullInjectionAuditStore;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;
use Padosoft\AiGuardrails\Tests\TestCase;

/**
 * The provider must wrap a real audit store with the hygiene decorator (E5), and leave the null
 * store unwrapped. Both-states on the master kill-switch and the prompt_storage mode.
 */
final class AuditHygieneBindingsTest extends TestCase
{
    private function resolveStore(): InjectionAuditStore
    {
        $this->app->forgetInstance(InjectionAuditStore::class);

        return $this->app->make(InjectionAuditStore::class);
    }

    private function attempt(string $prompt): InjectionAttempt
    {
        return new InjectionAttempt($prompt, true, 'rule', '42', new DateTimeImmutable('2026-01-01 10:00:00'), 'v1');
    }

    public function test_real_store_is_wrapped_with_hygiene_and_applies_the_mode(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'array');
        $this->app['config']->set('ai-guardrails.audit_hygiene.prompt_storage', 'hash');

        $store = $this->resolveStore();
        self::assertInstanceOf(HygienicInjectionAuditStore::class, $store);

        $store->append($this->attempt('secret prompt'));
        self::assertSame('sha256:'.hash('sha256', 'secret prompt'), $store->recent()[0]->prompt);
    }

    public function test_raw_mode_stores_the_prompt_verbatim(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'array');
        $this->app['config']->set('ai-guardrails.audit_hygiene.prompt_storage', 'raw');

        $store = $this->resolveStore();
        $store->append($this->attempt('mail john@example.com'));

        self::assertSame('mail john@example.com', $store->recent()[0]->prompt);
    }

    public function test_null_store_is_not_wrapped(): void
    {
        $this->app['config']->set('ai-guardrails.audit.store', 'null');

        self::assertInstanceOf(NullInjectionAuditStore::class, $this->resolveStore());
    }

    public function test_master_kill_switch_off_yields_null_store(): void
    {
        $this->app['config']->set('ai-guardrails.enabled', false);
        $this->app['config']->set('ai-guardrails.audit.store', 'array');

        self::assertInstanceOf(NullInjectionAuditStore::class, $this->resolveStore());
    }
}
