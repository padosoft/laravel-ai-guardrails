<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Padosoft\AiGuardrails\Firewall\SchemaToolArgumentValidator;
use Padosoft\AiGuardrails\Tests\Doubles\FakeOwnedTool;
use Padosoft\AiGuardrails\Tests\Doubles\FakeTypedTool;
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

    public function test_rejects_argument_with_unrecognised_schema_type(): void
    {
        $validator = new SchemaToolArgumentValidator(rejectUnknown: false);
        $matchesType = new \ReflectionMethod($validator, 'matchesType');

        // Unknown type keywords must fail-closed (return false) to prevent bypass on schema extensions.
        self::assertFalse($matchesType->invoke($validator, 'date', '2026-01-01'));
        self::assertFalse($matchesType->invoke($validator, 'int', 1));   // typo of 'integer'
        self::assertFalse($matchesType->invoke($validator, 'custom', 'value'));

        // 'null' IS a recognised JSON-schema type (used in nullable unions like ['string','null']).
        self::assertTrue($matchesType->invoke($validator, 'null', null));
        self::assertFalse($matchesType->invoke($validator, 'null', 'not-null'));
    }

    public function test_unknown_keys_allowed_when_reject_unknown_is_false(): void
    {
        $errors = (new SchemaToolArgumentValidator(rejectUnknown: false))
            ->validate(new FakeOwnedTool, ['order_id' => 'A1', 'extra' => 'y']);

        self::assertSame([], $errors);
    }

    public function test_array_and_object_types_are_distinguished(): void
    {
        $validator = new SchemaToolArgumentValidator(rejectUnknown: false);
        $matchesType = new \ReflectionMethod($validator, 'matchesType');

        self::assertTrue($matchesType->invoke($validator, 'array', [1, 2, 3]));   // list
        self::assertFalse($matchesType->invoke($validator, 'array', ['a' => 1])); // map is not a list
        self::assertTrue($matchesType->invoke($validator, 'object', ['a' => 1])); // map
        self::assertFalse($matchesType->invoke($validator, 'object', [1, 2, 3])); // list is not an object
    }

    public function test_nullable_union_type_accepts_null_and_member_type(): void
    {
        $validator = new SchemaToolArgumentValidator(rejectUnknown: true);

        // note is nullable string → ['string','null']: null and string both valid.
        self::assertSame([], $validator->validate(new FakeTypedTool, ['account_id' => 5, 'note' => null]));
        self::assertSame([], $validator->validate(new FakeTypedTool, ['account_id' => 5, 'note' => 'hi']));
    }

    public function test_union_type_rejects_value_matching_no_member(): void
    {
        $errors = (new SchemaToolArgumentValidator(rejectUnknown: true))
            ->validate(new FakeTypedTool, ['account_id' => 5, 'note' => 123]); // int is neither string nor null

        self::assertArrayHasKey('note', $errors);
    }
}
