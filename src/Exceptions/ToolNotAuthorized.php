<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Exceptions;

use RuntimeException;

/**
 * Thrown when the tool-authorization gate (Task E7) denies the current principal the use of a tool.
 * Distinct from ToolArgumentRejection (Control A): that is about the arguments, this is about whether
 * the principal may invoke the tool at all.
 */
final class ToolNotAuthorized extends RuntimeException
{
    public function __construct(public readonly string $toolIdentifier)
    {
        parent::__construct("Tool use not authorized for the current principal: [{$toolIdentifier}].");
    }
}
