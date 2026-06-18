<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Unit;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Padosoft\AiGuardrails\Output\StructuredOutputValidator;
use Padosoft\AiGuardrails\Tests\TestCase;

final class StructuredOutputValidatorTest extends TestCase
{
    /** @return array<string,Type> */
    private function schema(): array
    {
        $s = new JsonSchemaTypeFactory;

        return ['action' => $s->string()->required(), 'amount' => $s->integer()];
    }

    public function test_valid_structured_output_passes(): void
    {
        $errors = (new StructuredOutputValidator)->validate(['action' => 'refund', 'amount' => 5], $this->schema());

        self::assertSame([], $errors);
    }

    public function test_missing_required_field_is_reported(): void
    {
        $errors = (new StructuredOutputValidator)->validate(['amount' => 5], $this->schema());

        self::assertArrayHasKey('action', $errors);
    }

    public function test_wrong_type_is_reported(): void
    {
        $errors = (new StructuredOutputValidator)->validate(['action' => 'refund', 'amount' => 'x'], $this->schema());

        self::assertArrayHasKey('amount', $errors);
    }

    public function test_unknown_fields_allowed_by_default(): void
    {
        $errors = (new StructuredOutputValidator)->validate(['action' => 'refund', 'extra' => 1], $this->schema());

        self::assertSame([], $errors);
    }

    public function test_unknown_fields_reported_when_reject_unknown(): void
    {
        $errors = (new StructuredOutputValidator(rejectUnknown: true))->validate(['action' => 'refund', 'extra' => 1], $this->schema());

        self::assertArrayHasKey('extra', $errors);
    }

    public function test_nullable_union_field_accepts_null_and_member_type(): void
    {
        $s = new JsonSchemaTypeFactory;
        $schema = ['note' => $s->string()->nullable()]; // serializes as ['string','null']

        self::assertSame([], (new StructuredOutputValidator)->validate(['note' => null], $schema));
        self::assertSame([], (new StructuredOutputValidator)->validate(['note' => 'hi'], $schema));
        self::assertArrayHasKey('note', (new StructuredOutputValidator)->validate(['note' => 123], $schema));
    }

    /** Pins every `matchesType` arm so removing any (→ fail-closed `default => false`) is caught. */
    public function test_matches_type_covers_every_schema_type(): void
    {
        $v = new StructuredOutputValidator;
        $m = new \ReflectionMethod($v, 'matchesType');

        self::assertTrue($m->invoke($v, 'string', 'x'));
        self::assertFalse($m->invoke($v, 'string', 1));
        self::assertTrue($m->invoke($v, 'integer', 1));
        self::assertFalse($m->invoke($v, 'integer', 1.5));
        self::assertTrue($m->invoke($v, 'number', 1));
        self::assertTrue($m->invoke($v, 'number', 1.5));
        self::assertFalse($m->invoke($v, 'number', '1'));
        self::assertFalse($m->invoke($v, 'number', true)); // a bool is not a number
        self::assertTrue($m->invoke($v, 'boolean', true));
        self::assertFalse($m->invoke($v, 'boolean', 1));
        self::assertTrue($m->invoke($v, 'array', [1, 2]));
        self::assertFalse($m->invoke($v, 'array', ['a' => 1]));
        self::assertTrue($m->invoke($v, 'object', ['a' => 1]));
        self::assertFalse($m->invoke($v, 'object', [1, 2]));
        self::assertTrue($m->invoke($v, 'null', null));
        self::assertFalse($m->invoke($v, 'null', 0));
        self::assertFalse($m->invoke($v, 'unknown_type', 'x')); // default → fail-closed
    }

    public function test_optional_field_absent_from_output_is_skipped(): void
    {
        // 'amount' is optional; absent → not type-checked (the `continue` must stand).
        $errors = (new StructuredOutputValidator)->validate(['action' => 'refund'], $this->schema());

        self::assertArrayNotHasKey('amount', $errors);
        self::assertSame([], $errors);
    }
}
