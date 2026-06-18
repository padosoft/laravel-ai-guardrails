<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Mcp;

use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use Padosoft\AiGuardrails\Mcp\McpServerRegistrar;
use Padosoft\AiGuardrails\Tests\TestCase;

/** L5 — the provider registers the MCP server only when mcp.enabled (default OFF). */
final class McpRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), McpServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.mcp.enabled', true);
    }

    public function test_server_is_registered_when_mcp_enabled(): void
    {
        self::assertArrayHasKey(McpServerRegistrar::HANDLE, Mcp::servers());
    }
}
