<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Firewall;

use Padosoft\AiGuardrails\Contracts\ToolAuthorizer;

/** Null-object authorizer used when tool authorization is disabled — every tool is allowed. */
final class AllowAllToolAuthorizer implements ToolAuthorizer
{
    public function authorize(string $toolIdentifier): bool
    {
        return true;
    }
}
