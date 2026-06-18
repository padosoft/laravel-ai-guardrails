<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Mcp;

use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server as McpServer;

/**
 * Registers the ai-guardrails MCP server (Task L5), gracefully no-op when laravel/mcp is absent.
 * Keeps the `Laravel\Mcp\*` reference inside the src/Mcp adapter boundary so the service provider
 * never references the optional vendor directly (compose-not-couple). The provider calls this only
 * when `mcp.enabled` is true.
 */
final class McpServerRegistrar
{
    /** The local (stdio) handle the host's MCP client connects to. */
    public const HANDLE = 'ai-guardrails';

    public static function registerIfAvailable(): void
    {
        // `use` aliases above do not autoload; this string check is the real presence probe.
        if (! class_exists(McpServer::class)) {
            return;
        }

        Mcp::local(self::HANDLE, GuardrailsMcpServer::class);
    }
}
