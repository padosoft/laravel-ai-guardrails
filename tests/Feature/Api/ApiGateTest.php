<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Tests\Feature\Api;

use Padosoft\AiGuardrails\Tests\TestCase;

final class ApiGateTest extends TestCase
{
    public function test_api_routes_are_absent_when_disabled_by_default(): void
    {
        // Default config: api.enabled = false → no routes registered.
        $this->getJson('/ai-guardrails/api/overview')->assertNotFound();
        $this->postJson('/ai-guardrails/api/try/screen', ['prompt' => 'x'])->assertNotFound();
    }
}
