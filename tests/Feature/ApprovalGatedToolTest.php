<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use DateTimeImmutable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\AiGuardrails\Hitl\ApprovalGatedTool;
use Padosoft\AiGuardrails\Hitl\NullApprovalRouter;
use Padosoft\AiGuardrails\Hitl\PendingApproval;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\TestCase;
use RuntimeException;

final class ApprovalGatedToolTest extends TestCase
{
    private function availableRouter(): ApprovalRouter
    {
        return new class implements ApprovalRouter
        {
            public ?string $routedTool = null;

            public function isAvailable(): bool
            {
                return true;
            }

            public function route(string $toolName, string $toolClass, array $arguments, int|string|null $principalId): PendingApproval
            {
                $this->routedTool = $toolName;

                return new PendingApproval('tok-123', 'run-1', $toolName, new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $arguments);
            }

            public function approve(string $token, array $actor = []): void {}

            public function reject(string $token, array $actor = []): void {}
        };
    }

    public function test_parks_destructive_call_for_approval_without_executing(): void
    {
        $tool = new FakeDestructiveTool;
        $gated = new ApprovalGatedTool($tool, $this->availableRouter(), static fn (): string => '42', 'refund');

        $result = (string) $gated->handle(new Request(['order_id' => 'A1']));

        self::assertStringContainsString('requires human approval', $result);
        // The plain-text token must NOT appear in the model response (prevent token leakage).
        self::assertStringNotContainsString('tok-123', $result);
        // The non-secret run reference IS included so the operator can look it up.
        self::assertStringContainsString('run-1', $result);
        self::assertFalse($tool->executed, 'the destructive tool must NOT run before approval');
    }

    public function test_fallback_deny_refuses_when_approval_unavailable(): void
    {
        $tool = new FakeDestructiveTool;
        $gated = new ApprovalGatedTool($tool, new NullApprovalRouter, static fn (): string => '42', 'refund', fallback: 'deny');

        $result = (string) $gated->handle(new Request(['order_id' => 'A1']));

        self::assertStringContainsString('blocked', $result);
        self::assertFalse($tool->executed);
    }

    public function test_fallback_pass_executes_when_approval_unavailable(): void
    {
        $tool = new FakeDestructiveTool;
        $gated = new ApprovalGatedTool($tool, new NullApprovalRouter, static fn (): string => '42', 'refund', fallback: 'pass');

        $result = (string) $gated->handle(new Request(['order_id' => 'A1']));

        self::assertSame('refunded', $result);
        self::assertTrue($tool->executed);
    }

    public function test_passes_description_and_schema_through(): void
    {
        $gated = new ApprovalGatedTool(new FakeDestructiveTool, new NullApprovalRouter, static fn (): null => null, 'refund');

        self::assertSame('Refund an order.', (string) $gated->description());
        self::assertArrayHasKey('order_id', $gated->schema(new JsonSchemaTypeFactory));
    }

    public function test_invalid_fallback_value_throws_on_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/fallback must be 'deny' or 'pass'/");

        new ApprovalGatedTool(new FakeDestructiveTool, new NullApprovalRouter, static fn (): null => null, 'refund', fallback: 'allow');
    }

    public function test_routing_exception_falls_back_to_deny_not_throw(): void
    {
        $throwingRouter = new class implements ApprovalRouter
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function route(string $toolName, string $toolClass, array $arguments, int|string|null $principalId): PendingApproval
            {
                throw new RuntimeException('flow misconfigured');
            }

            public function approve(string $token, array $actor = []): void {}

            public function reject(string $token, array $actor = []): void {}
        };

        $tool = new FakeDestructiveTool;
        $gated = new ApprovalGatedTool($tool, $throwingRouter, static fn (): null => null, 'refund');

        $result = (string) $gated->handle(new Request(['order_id' => 'A1']));

        self::assertStringContainsString('blocked', $result);
        self::assertFalse($tool->executed, 'delegate must NOT execute when routing fails');
    }
}
