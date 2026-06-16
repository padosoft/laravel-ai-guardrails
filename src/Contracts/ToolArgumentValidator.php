<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Contracts;

use Laravel\Ai\Contracts\Tool;

interface ToolArgumentValidator
{
    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,string> Map of argument key => violation message (empty = valid).
     */
    public function validate(Tool $tool, array $arguments): array;
}
