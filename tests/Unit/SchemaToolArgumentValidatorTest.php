<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Firewall\SchemaToolArgumentValidator;
use Padosoft\AiGuardrails\Tests\Doubles\FakeOwnedTool;
use Padosoft\AiGuardrails\Tests\TestCase;

final class SchemaToolArgumentValidatorTest extends TestCase
{
    public function test_accepts_arguments_matching_schema(): void
    {
        $errors = (new SchemaToolArgumentValidator(rejectUnknown: true))
            ->validate(new FakeOwnedTool, ['amount' => 10, 'order_id' => 'A1']);

        self::assertSame([], $errors);
    }

    public function test_rejects_missing_required_and_unknown_keys(): void
    {
        $errors = (new SchemaToolArgumentValidator(rejectUnknown: true))
            ->validate(new FakeOwnedTool, ['evil' => 'x']);

        self::assertArrayHasKey('order_id', $errors); // required, missing
        self::assertArrayHasKey('evil', $errors);     // unknown, rejected
    }

    public function test_rejects_wrong_type(): void
    {
        $errors = (new SchemaToolArgumentValidator(rejectUnknown: true))
            ->validate(new FakeOwnedTool, ['amount' => 'not-a-number', 'order_id' => 'A1']);

        self::assertArrayHasKey('amount', $errors);
    }

    public function test_unknown_keys_allowed_when_reject_unknown_is_false(): void
    {
        $errors = (new SchemaToolArgumentValidator(rejectUnknown: false))
            ->validate(new FakeOwnedTool, ['order_id' => 'A1', 'extra' => 'y']);

        self::assertSame([], $errors);
    }
}
