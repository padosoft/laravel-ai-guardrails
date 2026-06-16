<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Laravel\Ai\Contracts\Tool;
use Padosoft\AiGuardrails\Contracts\ToolArgumentValidator;

/**
 * No-op validator bound when tool_firewall.enabled = false.
 * Always returns an empty errors array (all arguments accepted).
 */
final class PermissiveToolArgumentValidator implements ToolArgumentValidator
{
    public function validate(Tool $tool, array $arguments): array
    {
        return [];
    }
}
