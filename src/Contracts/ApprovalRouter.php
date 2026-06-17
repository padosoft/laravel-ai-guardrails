<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Padosoft\AiGuardrails\Hitl\PendingApproval;

interface ApprovalRouter
{
    /**
     * Whether human-in-the-loop approval is actually available (e.g. padosoft/laravel-flow is
     * installed and configured). When false, the gated tool applies its configured fallback.
     */
    public function isAvailable(): bool;

    /**
     * Park a destructive tool call for human approval instead of executing it. Returns the issued
     * approval token + run metadata.
     *
     * @param  class-string  $toolClass
     * @param  array<string,mixed>  $arguments
     */
    public function route(string $toolName, string $toolClass, array $arguments, int|string|null $principalId): PendingApproval;

    /** @param array<string,mixed> $actor */
    public function approve(string $token, array $actor = []): void;

    /** @param array<string,mixed> $actor */
    public function reject(string $token, array $actor = []): void;
}
