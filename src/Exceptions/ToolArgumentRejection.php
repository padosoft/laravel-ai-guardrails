<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Exceptions;

use RuntimeException;

final class ToolArgumentRejection extends RuntimeException
{
    /** @param array<string,string> $violations */
    public function __construct(
        public readonly array $violations,
        string $toolDescription,
    ) {
        parent::__construct("Tool arguments rejected by firewall for tool [{$toolDescription}].");
    }
}
