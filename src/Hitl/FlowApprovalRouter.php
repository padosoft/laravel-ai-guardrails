<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Hitl;

use Padosoft\AiGuardrails\Contracts\ApprovalRouter;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\IssuedApprovalToken;
use RuntimeException;

/**
 * HITL bridge (Control D) over padosoft/laravel-flow. A destructive tool call is parked by executing
 * a small flow [approvalGate -> ToolApprovalHandler]: the flow pauses at the gate and issues an
 * approval token; on approve() the flow resumes and the handler runs the real tool. Composes flow —
 * does not reinvent approvals. Referenced only within the src/Hitl adapter boundary.
 */
final class FlowApprovalRouter implements ApprovalRouter
{
    /** The flow definition name for the guardrails tool-approval flow. Public so the read model can
     *  scope `flow_approvals` to this package's runs only. */
    public const FLOW_NAME = 'ai-guardrails-tool-approval';

    private const GATE = 'approval';

    private bool $registered = false;

    public function isAvailable(): bool
    {
        return true;
    }

    public function route(string $toolName, string $toolClass, array $arguments, int|string|null $principalId): PendingApproval
    {
        $this->ensureRegistered();

        $run = Flow::execute(self::FLOW_NAME, [
            'tool' => $toolName,
            'tool_class' => $toolClass,
            'arguments' => $arguments,
            'principal_id' => $principalId,
        ]);

        $token = $run->approvalTokens[self::GATE] ?? null;
        if (! $token instanceof IssuedApprovalToken) {
            throw new RuntimeException('laravel-flow did not issue an approval token for the destructive tool call.');
        }

        return new PendingApproval($token->plainTextToken, $run->id, $toolName, $token->expiresAt, $arguments);
    }

    public function approve(string $token, array $actor = []): void
    {
        // Register the definition first: a decision may arrive in a fresh request/worker that never
        // called route(), and Flow::resume reconstructs the run from the registered definition.
        $this->ensureRegistered();

        Flow::resume($token, [], $actor);
    }

    public function reject(string $token, array $actor = []): void
    {
        $this->ensureRegistered();

        Flow::reject($token, [], $actor);
    }

    private function ensureRegistered(): void
    {
        if ($this->registered) {
            return;
        }

        Flow::define(self::FLOW_NAME)
            ->withInput(['tool', 'tool_class', 'arguments'])
            ->approvalGate(self::GATE)
            ->step('execute', ToolApprovalHandler::class)
            ->register();

        $this->registered = true;
    }
}
