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
 *
 * Security: tool_class is validated against the `hitl.allowed_tool_classes` allowlist (when
 * configured) before resolving from the IoC container, preventing arbitrary-class dispatch if the
 * flow persistence layer is compromised. The principal_id is included in the step output for the
 * host's audit trail.
 */
final class ToolApprovalHandler implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        $toolClass = $context->input['tool_class'] ?? null;
        $arguments = $context->input['arguments'] ?? [];
        $principalId = $context->input['principal_id'] ?? null;

        if (! is_string($toolClass) || ! class_exists($toolClass)) {
            return FlowStepResult::failed(new RuntimeException('Approved tool class is not resolvable.'));
        }

        // Allowlist check: when hitl.allowed_tool_classes is non-empty, only listed classes may run.
        /** @var list<class-string> $allowedClasses */
        $allowedClasses = config('ai-guardrails.hitl.allowed_tool_classes', []);
        if (! empty($allowedClasses) && ! in_array($toolClass, $allowedClasses, true)) {
            return FlowStepResult::failed(new RuntimeException(
                "Tool class [{$toolClass}] is not in the hitl.allowed_tool_classes allowlist."
            ));
        }

        $tool = app($toolClass);
        if (! $tool instanceof Tool) {
            return FlowStepResult::failed(new RuntimeException('Approved class is not a laravel/ai Tool.'));
        }

        $result = $tool->handle(new Request(is_array($arguments) ? $arguments : []));

        return FlowStepResult::success([
            'result' => (string) $result,
            'principal_id' => $principalId,
        ]);
    }
}
