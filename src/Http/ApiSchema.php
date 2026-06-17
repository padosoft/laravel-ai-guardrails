<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Http;

/**
 * Version markers for the read/config HTTP API envelope. Every payload carries `schema_version`
 * (the envelope version UI clients pin against) and a per-endpoint `schema` discriminator. Mirrors
 * the padosoft-eval-harness ReportApi house style. Additive endpoints keep VERSION stable.
 */
final class ApiSchema
{
    public const VERSION = 'ai-guardrails.api.v1';

    public const SCHEMA_OVERVIEW = 'ai-guardrails.api.v1.overview';

    public const SCHEMA_AUDIT_LIST = 'ai-guardrails.api.v1.audit-list';

    public const SCHEMA_AUDIT_DETAIL = 'ai-guardrails.api.v1.audit-detail';

    public const SCHEMA_AUDIT_TREND = 'ai-guardrails.api.v1.audit-trend';

    public const SCHEMA_FIREWALL = 'ai-guardrails.api.v1.firewall';

    public const SCHEMA_OUTPUT_STATS = 'ai-guardrails.api.v1.output-stats';

    public const SCHEMA_APPROVALS = 'ai-guardrails.api.v1.approvals';

    public const SCHEMA_SETTINGS = 'ai-guardrails.api.v1.settings';

    public const SCHEMA_TRY_SCREEN = 'ai-guardrails.api.v1.try-screen';

    public const SCHEMA_TRY_SANITIZE = 'ai-guardrails.api.v1.try-sanitize';
}
