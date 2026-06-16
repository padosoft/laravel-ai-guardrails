<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\Tests\TestCase;

final class PackageBootsTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        self::assertTrue(config('ai-guardrails.enabled'));
        self::assertSame('null', config('ai-guardrails.audit.store'));
    }

    public function test_enterprise_hardening_config_present(): void
    {
        self::assertSame('enforce', config('ai-guardrails.modes.input_screen'));
        self::assertSame('closed', config('ai-guardrails.pattern_safety.on_match_error'));
        self::assertSame('redact', config('ai-guardrails.audit_hygiene.prompt_storage'));
        self::assertFalse(config('ai-guardrails.api.enabled'));
    }
}
