<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Screening\ScreenVerdict;
use PHPUnit\Framework\TestCase;

final class ScreenVerdictTest extends TestCase
{
    public function test_allow_produces_unblocked_verdict_with_no_rule(): void
    {
        $verdict = ScreenVerdict::allow();

        self::assertFalse($verdict->blocked);
        self::assertNull($verdict->ruleId);
        self::assertNull($verdict->refusalMessage);
    }

    public function test_block_produces_blocked_verdict_with_required_fields(): void
    {
        $verdict = ScreenVerdict::block('rule_id_01', 'Request blocked.');

        self::assertTrue($verdict->blocked);
        self::assertSame('rule_id_01', $verdict->ruleId);
        self::assertSame('Request blocked.', $verdict->refusalMessage);
    }

    public function test_constructor_is_private_preventing_invalid_blocked_state(): void
    {
        $ref = new \ReflectionClass(ScreenVerdict::class);

        self::assertTrue($ref->getConstructor()?->isPrivate(), 'ScreenVerdict::__construct must be private to enforce the allow/block invariant.');
    }
}
