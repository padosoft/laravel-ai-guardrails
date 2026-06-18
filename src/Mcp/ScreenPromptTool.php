<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Padosoft\AiGuardrails\Facades\AiGuardrails;

/**
 * MCP tool (Task L5): screen a prompt for injection through Control B and return the verdict. The
 * optional-vendor reference (`Laravel\Mcp\*`) is confined to this src/Mcp adapter boundary.
 */
final class ScreenPromptTool extends Tool
{
    protected string $name = 'screen_prompt';

    protected string $description = 'Screen a prompt for prompt-injection. Returns whether it is blocked, the matched rule id, the active ruleset version, and the refusal message.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->description('The user prompt to screen for injection.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $verdict = AiGuardrails::screen((string) $request->get('prompt', ''));

        return Response::json([
            'blocked' => $verdict->blocked,
            'rule_id' => $verdict->ruleId,
            'ruleset_version' => $verdict->rulesetVersion,
            'message' => $verdict->blocked ? $verdict->refusalMessage : null,
        ]);
    }
}
