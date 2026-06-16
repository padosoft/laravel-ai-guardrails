<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\AiGuardrails;
use Padosoft\AiGuardrails\Facades\AiGuardrails as AiGuardrailsFacade;
use Padosoft\AiGuardrails\Screening\ScreenVerdict;
use Padosoft\AiGuardrails\Tests\TestCase;

final class FacadeResolvesTest extends TestCase
{
    /** @return array<string,class-string> */
    protected function getPackageAliases($app): array
    {
        return ['AiGuardrails' => AiGuardrailsFacade::class];
    }

    public function test_core_service_resolves_by_class(): void
    {
        self::assertInstanceOf(AiGuardrails::class, $this->resolve(AiGuardrails::class));
    }

    public function test_alias_resolves_to_core_service(): void
    {
        // The 'ai-guardrails' alias is a container string, not a class-string, so it is
        // resolved via the app() helper rather than the class-string-typed resolve() helper.
        self::assertInstanceOf(AiGuardrails::class, app('ai-guardrails'));
    }

    public function test_screen_returns_a_verdict(): void
    {
        $verdict = AiGuardrailsFacade::screen('hello world');

        self::assertInstanceOf(ScreenVerdict::class, $verdict);
        self::assertFalse($verdict->blocked);
    }

    public function test_sanitize_passes_text_through_null_pipeline(): void
    {
        self::assertSame('hello', AiGuardrailsFacade::sanitize('hello'));
    }
}
