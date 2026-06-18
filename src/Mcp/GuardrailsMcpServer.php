<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * The ai-guardrails MCP server (Task L5) — a fourth surface (after PHP, Artisan, HTTP API) exposing
 * the deterministic guardrail capabilities to MCP clients. Registered via `Mcp::local('ai-guardrails',
 * GuardrailsMcpServer::class)` by the provider when `mcp.enabled` and laravel/mcp is installed.
 *
 * Vendor reference (`Laravel\Mcp\*`) is confined to this src/Mcp adapter boundary (compose-not-couple).
 */
final class GuardrailsMcpServer extends Server
{
    protected string $name = 'AI Guardrails';

    protected string $version = '1.0.0';

    protected string $instructions = 'Deterministic, offline prompt-injection guardrails: screen prompts, sanitize untrusted output, and read the injection audit.';

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ScreenPromptTool::class,
        SanitizeOutputTool::class,
        RecentAuditTool::class,
    ];
}
