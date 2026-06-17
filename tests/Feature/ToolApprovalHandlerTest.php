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
        ]);

        $result = (new ToolApprovalHandler)->execute($context);

        self::assertTrue($result->success);
        self::assertSame('refunded', $result->output['result']);
    }

    public function test_fails_when_tool_class_is_unresolvable(): void
    {
        $context = new FlowContext('run-1', 'flow', ['tool_class' => 'Not\\A\\Class', 'arguments' => []]);

        $result = (new ToolApprovalHandler)->execute($context);

        self::assertFalse($result->success);
    }
}
