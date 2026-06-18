<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Padosoft\AiGuardrails\Audit\InjectionAttempt;
use Padosoft\AiGuardrails\Contracts\InjectionAuditStore;

/**
 * MCP tool (Task L5): read the most recent injection-audit attempts (Control B's append-only log).
 * Read-only — exposes the audit value to an MCP client. Vendor reference confined to src/Mcp.
 */
final class RecentAuditTool extends Tool
{
    protected string $name = 'recent_injection_audit';

    protected string $description = 'List the most recent injection-screening attempts (blocked and allowed) from the append-only audit.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('How many recent attempts to return (1–100).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = max(1, min(100, (int) $request->get('limit', 20)));
        $rows = app(InjectionAuditStore::class)->recent($limit);

        return Response::json([
            'attempts' => array_map(static fn (InjectionAttempt $a): array => [
                'blocked' => $a->blocked,
                'rule_id' => $a->ruleId,
                'principal_id' => $a->principalId,
                'ruleset_version' => $a->rulesetVersion,
                'occurred_at' => $a->occurredAt->format(DATE_ATOM),
            ], $rows),
        ]);
    }
}
