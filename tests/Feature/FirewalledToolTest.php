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
use Padosoft\AiGuardrails\Tests\Doubles\FakeTypedTool;
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

    public function test_default_owner_keys_not_declared_by_the_tool_do_not_break_the_call(): void
    {
        // Regression: with the full default owner_keys, only the keys the tool declares (user_id)
        // are injected — the rest must not be injected and then rejected as unknown.
        $tool = new FakeOwnedTool;
        $wrapped = new FirewalledTool(
            $tool,
            new UserScopedArgumentScoper(['user_id', 'owner_id', 'account_id', 'customer_id']),
            new SchemaToolArgumentValidator(rejectUnknown: true),
            principalResolver: static fn (): string => '42',
        );

        $result = $wrapped->handle(new Request(['order_id' => 'A1']));

        self::assertSame('ok', (string) $result);
        self::assertSame('42', $tool->received['user_id']);
        self::assertArrayNotHasKey('owner_id', $tool->received);
        self::assertArrayNotHasKey('account_id', $tool->received);
    }

    public function test_integer_owner_key_receives_integer_principal(): void
    {
        $tool = new FakeTypedTool;
        $wrapped = new FirewalledTool(
            $tool,
            new UserScopedArgumentScoper(['account_id']),
            new SchemaToolArgumentValidator(rejectUnknown: true),
            principalResolver: static fn (): string => '42',
        );

        $wrapped->handle(new Request(['account_id' => 999, 'note' => 'hi']));

        self::assertSame(42, $tool->received['account_id']); // int, passes integer validation
    }
}
