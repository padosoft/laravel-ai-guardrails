<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * The flow step that runs AFTER a human approves: it resolves the original destructive tool by
 * class and executes it with the scoped arguments that were parked. Runs inside padosoft/laravel-flow
 * (only referenced here, within the src/Hitl adapter boundary).
 */
final class ToolApprovalHandler implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        $toolClass = $context->input['tool_class'] ?? null;
        $arguments = $context->input['arguments'] ?? [];

        if (! is_string($toolClass) || ! class_exists($toolClass)) {
            return FlowStepResult::failed(new RuntimeException('Approved tool class is not resolvable.'));
        }

        $tool = app($toolClass);
        if (! $tool instanceof Tool) {
            return FlowStepResult::failed(new RuntimeException('Approved class is not a laravel/ai Tool.'));
        }

        $result = $tool->handle(new Request(is_array($arguments) ? $arguments : []));

        return FlowStepResult::success(['result' => (string) $result]);
    }
}
