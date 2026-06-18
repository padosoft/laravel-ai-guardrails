<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Mcp;

use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use Padosoft\AiGuardrails\Mcp\McpServerRegistrar;
use Padosoft\AiGuardrails\Tests\TestCase;

/** L5 — with mcp.enabled off (the default), the guardrail MCP server is NOT registered. */
final class McpRegistrationDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), McpServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai-guardrails.mcp.enabled', false);
    }

    public function test_server_is_not_registered_when_mcp_disabled(): void
    {
        self::assertArrayNotHasKey(McpServerRegistrar::HANDLE, Mcp::servers());
    }
}
