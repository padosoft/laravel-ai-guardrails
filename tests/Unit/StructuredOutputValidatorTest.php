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
}
