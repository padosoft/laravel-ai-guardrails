<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Exceptions\ToolArgumentRejection;
use Padosoft\AiGuardrails\Firewall\FirewalledTool;
use Padosoft\AiGuardrails\Firewall\SchemaToolArgumentValidator;
use Padosoft\AiGuardrails\Firewall\UserScopedArgumentScoper;
use Padosoft\AiGuardrails\Tests\Doubles\FakeOwnedTool;
use Padosoft\AiGuardrails\Tests\TestCase;

final class FirewalledToolTest extends TestCase
{
    private function wrap(FakeOwnedTool $tool): FirewalledTool
    {
        return new FirewalledTool(
            $tool,
            new UserScopedArgumentScoper(['user_id']),
            new SchemaToolArgumentValidator(rejectUnknown: true),
            principalResolver: static fn (): string => '42',
        );
    }

    public function test_rescopes_owner_key_before_delegating(): void
    {
        $tool = new FakeOwnedTool;

        $result = $this->wrap($tool)->handle(new Request(['order_id' => 'A1', 'user_id' => '999']));

        self::assertSame('ok', (string) $result);
        self::assertSame('42', $tool->received['user_id']); // model's 999 was overwritten
        self::assertSame('A1', $tool->received['order_id']);
    }

    public function test_rejects_unknown_argument_without_delegating(): void
    {
        $tool = new FakeOwnedTool;

        try {
            $this->wrap($tool)->handle(new Request(['order_id' => 'A1', 'evil' => 'x']));
            self::fail('Expected ToolArgumentRejection.');
        } catch (ToolArgumentRejection $e) {
            self::assertArrayHasKey('evil', $e->violations);
            self::assertNull($tool->received); // delegate never ran
        }
    }

    public function test_passes_description_and_schema_through(): void
    {
        $tool = new FakeOwnedTool;
        $wrapped = $this->wrap($tool);

        self::assertSame('Issue a refund for an order.', (string) $wrapped->description());
        self::assertArrayHasKey('order_id', $wrapped->schema(new JsonSchemaTypeFactory));
    }
}
