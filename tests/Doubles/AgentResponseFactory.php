<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Doubles;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

final class AgentResponseFactory
{
    public static function make(string $text, ?string $invocationId = 'inv-1'): AgentResponse
    {
        return new AgentResponse($invocationId ?? '', $text, new Usage, new Meta);
    }
}
