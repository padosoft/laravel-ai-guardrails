<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Doubles;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Mockery;

/**
 * Builds a real AgentPrompt for tests. The Agent + TextProvider are mocked because the guardrail
 * input middleware never invokes them (it only reads $prompt->prompt and $prompt->invocationId,
 * and on the allow path delegates to the test-supplied $next closure).
 */
final class AgentPromptFactory
{
    public static function make(string $prompt, ?string $invocationId = 'inv-1'): AgentPrompt
    {
        return new AgentPrompt(
            Mockery::mock(Agent::class),
            $prompt,
            [],
            Mockery::mock(TextProvider::class),
            'test-model',
            null,
            $invocationId,
        );
    }
}
