<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature;

use Padosoft\AiGuardrails\Hitl\ToolApprovalHandler;
use Padosoft\AiGuardrails\Tests\Doubles\FakeDestructiveTool;
use Padosoft\AiGuardrails\Tests\TestCase;
use Padosoft\LaravelFlow\FlowContext;

final class ToolApprovalHandlerTest extends TestCase
{
    public function test_executes_the_approved_tool_and_returns_its_result(): void
    {
        $context = new FlowContext('run-1', 'flow', [
            'tool_class' => FakeDestructiveTool::class,
            'arguments' => ['order_id' => 'A1'],
            'principal_id' => '42',
        ]);

        $result = (new ToolApprovalHandler)->execute($context);

        self::assertTrue($result->success);
        self::assertSame('refunded', $result->output['result']);
        self::assertSame('42', $result->output['principal_id']);
    }

    public function test_fails_when_tool_class_is_unresolvable(): void
    {
        $context = new FlowContext('run-1', 'flow', ['tool_class' => 'Not\\A\\Class', 'arguments' => []]);

        $result = (new ToolApprovalHandler)->execute($context);

        self::assertFalse($result->success);
    }

    public function test_fails_when_tool_class_not_in_allowlist(): void
    {
        $this->app['config']->set('ai-guardrails.hitl.allowed_tool_classes', ['App\\Tools\\WhitelistedTool']);

        $context = new FlowContext('run-1', 'flow', [
            'tool_class' => FakeDestructiveTool::class,
            'arguments' => ['order_id' => 'A1'],
        ]);

        $result = (new ToolApprovalHandler)->execute($context);

        self::assertFalse($result->success);
        self::assertStringContainsString('allowlist', $result->error->getMessage());
    }

    public function test_passes_when_tool_class_in_allowlist(): void
    {
        $this->app['config']->set('ai-guardrails.hitl.allowed_tool_classes', [FakeDestructiveTool::class]);

        $context = new FlowContext('run-1', 'flow', [
            'tool_class' => FakeDestructiveTool::class,
            'arguments' => ['order_id' => 'A1'],
        ]);

        $result = (new ToolApprovalHandler)->execute($context);

        self::assertTrue($result->success);
    }

    public function test_passes_when_allowlist_is_empty(): void
    {
        $this->app['config']->set('ai-guardrails.hitl.allowed_tool_classes', []);

        $context = new FlowContext('run-1', 'flow', [
            'tool_class' => FakeDestructiveTool::class,
            'arguments' => ['order_id' => 'A1'],
        ]);

        $result = (new ToolApprovalHandler)->execute($context);

        self::assertTrue($result->success);
    }
}
