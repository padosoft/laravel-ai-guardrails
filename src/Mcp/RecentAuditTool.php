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

    protected string $description = 'List the most recent injection-screening attempts (blocked and allowed) from the append-only audit. Principal identifiers are omitted by default; pass include_principal_ids=true to include them.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('How many recent attempts to return (1–100).'),
            'include_principal_ids' => $schema->boolean()->description('Include the principal_id field in each attempt. Default false — omit to avoid exposing internal user identifiers to the MCP client.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = max(1, min(100, (int) $request->get('limit', 20)));
        $includePrincipalIds = (bool) $request->get('include_principal_ids', false);
        $rows = app(InjectionAuditStore::class)->recent($limit);

        return Response::json([
            'attempts' => array_map(static function (InjectionAttempt $a) use ($includePrincipalIds): array {
                $entry = [
                    'blocked' => $a->blocked,
                    'rule_id' => $a->ruleId,
                    'ruleset_version' => $a->rulesetVersion,
                    'occurred_at' => $a->occurredAt->format(DATE_ATOM),
                ];

                if ($includePrincipalIds) {
                    $entry['principal_id'] = $a->principalId;
                }

                return $entry;
            }, $rows),
        ]);
    }
}
