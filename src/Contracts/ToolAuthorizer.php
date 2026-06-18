<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

/**
 * Decides whether the current principal is authorized to invoke a given tool (Task E7). This is
 * ABOVE Control A's owner-key re-scoping: re-scoping prevents acting on another user's resources,
 * authorization decides whether the principal may use the tool at all. Default-OFF; fail-closed
 * when enabled (an undefined policy denies).
 */
interface ToolAuthorizer
{
    /** @param  string  $toolIdentifier  the delegate tool's class (passed to the host policy). */
    public function authorize(string $toolIdentifier): bool;
}
