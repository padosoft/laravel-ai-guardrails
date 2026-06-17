<?php

declare(strict_types=1);

return [
    // Master kill-switch. When false, every decorator/middleware degrades to pass-through.
    'enabled' => env('AI_GUARDRAILS_ENABLED', true),

    // Control A — Tool firewall.
    'tool_firewall' => [
        'enabled' => env('AI_GUARDRAILS_TOOL_FIREWALL_ENABLED', true),
        // Argument keys the model is NEVER allowed to choose; overwritten server-side.
        'owner_keys' => ['user_id', 'owner_id', 'account_id', 'customer_id'],
        // Reject arguments not present in the tool schema (untrusted-input posture).
        'reject_unknown_arguments' => true,
    ],

    // Control B — Input screening + injection audit.
    'input_screen' => [
        'enabled' => env('AI_GUARDRAILS_INPUT_SCREEN_ENABLED', true),
        'refusal_message' => 'This request was blocked by the input guardrails.',
        // Case-insensitive substrings + regexes. The AUDIT is the value, not the list.
        'patterns' => [
            'ignore_previous' => '/\bignore\s+(all\s+)?previous\s+instructions?\b/iu',
            'reveal_system_prompt' => '/\b(reveal|show|print|repeat)\b.{0,30}\b(system\s+prompt|instructions)\b/iu',
            'role_override' => '/\byou\s+are\s+now\b|\bact\s+as\b.{0,40}\b(admin|root|developer\s+mode)\b/iu',
            'exfiltrate' => '/\b(send|email|post|upload)\b.{0,40}\b(api[_\s-]?key|secret|password|token)\b/iu',
        ],
    ],

    // Control C — Output handler.
    'output_handler' => [
        'enabled' => env('AI_GUARDRAILS_OUTPUT_HANDLER_ENABLED', true),
        'sanitize_html' => true,
        'neutralize_markdown' => true,
        // 'escape' (htmlspecialchars everything) or 'allowlist' (permit a safe tag set). Task E8.
        'html_mode' => env('AI_GUARDRAILS_HTML_MODE', 'escape'),
        // PII redaction is delegated to padosoft/laravel-pii-redactor when present.
        'redact_pii' => env('AI_GUARDRAILS_REDACT_PII', true),
    ],

    // Control D — HITL bridge (default-OFF: requires padosoft/laravel-flow + persistence).
    'hitl' => [
        'enabled' => env('AI_GUARDRAILS_HITL_ENABLED', false),
        // Tool names treated as destructive and routed through approvalGate().
        'destructive_tools' => ['refund', 'delete', 'send_email'],
        // When flow is absent and a destructive tool is called: 'deny' (safe) or 'pass'.
        'fallback' => env('AI_GUARDRAILS_HITL_FALLBACK', 'deny'),
    ],

    // Append-only injection audit persistence.
    'audit' => [
        'store' => env('AI_GUARDRAILS_AUDIT_STORE', 'null'), // null | array | database
        'connection' => env('AI_GUARDRAILS_AUDIT_CONNECTION'),
        'table' => env('AI_GUARDRAILS_AUDIT_TABLE', 'ai_guardrails_injection_audit'),
    ],

    // Append-only firewall-rejection persistence (control A; consumed by GET /firewall). Task 13.
    'firewall_log' => [
        'store' => env('AI_GUARDRAILS_FIREWALL_STORE', 'null'), // null | array | database
        'connection' => env('AI_GUARDRAILS_FIREWALL_CONNECTION'),
        'table' => env('AI_GUARDRAILS_FIREWALL_TABLE', 'ai_guardrails_firewall_rejections'),
    ],

    // Append-only output-sanitization counter persistence (control C; consumed by GET /output/stats). Task 14.
    'output_stats' => [
        'store' => env('AI_GUARDRAILS_OUTPUT_STATS_STORE', 'null'), // null | array | database
        'connection' => env('AI_GUARDRAILS_OUTPUT_STATS_CONNECTION'),
        'table' => env('AI_GUARDRAILS_OUTPUT_STATS_TABLE', 'ai_guardrails_output_stats'),
    ],

    // Runtime-overridable settings store (consumed by GET/PUT /settings). DB rows override file defaults. Task 16.
    'settings' => [
        'store' => env('AI_GUARDRAILS_SETTINGS_STORE', 'config'), // config | database
        'connection' => env('AI_GUARDRAILS_SETTINGS_CONNECTION'),
        'table' => env('AI_GUARDRAILS_SETTINGS_TABLE', 'ai_guardrails_settings'),
        // Only these keys may be overridden at runtime by the admin (allow-list, untrusted input).
        'overridable' => [
            'tool_firewall.enabled', 'tool_firewall.reject_unknown_arguments',
            'input_screen.enabled', 'input_screen.refusal_message',
            'output_handler.enabled', 'output_handler.sanitize_html',
            'output_handler.neutralize_markdown', 'output_handler.redact_pii', 'output_handler.html_mode',
            'hitl.enabled', 'hitl.fallback',
            'modes.tool_firewall', 'modes.input_screen', 'modes.output_handler', 'modes.hitl',
            'normalization.enabled', 'pattern_safety.on_match_error',
            'tool_authorization.enabled', 'tool_authorization.owner_key_depth', 'tool_authorization.destructive_match',
        ],
    ],

    // HTTP API surface (consumed by the laravel-ai-guardrails-admin SPA). DEFAULT-OFF. Task 9.
    'api' => [
        'enabled' => env('AI_GUARDRAILS_API_ENABLED', false),
        'prefix' => env('AI_GUARDRAILS_API_PREFIX', 'ai-guardrails/api'),
        'middleware' => [], // host adds 'api', auth, throttle as needed
    ],

    // ── ENTERPRISE HARDENING (Tasks E1–E9) ──────────────────────────────────

    // Per-control enforcement mode. 'enforce' = block, 'monitor' = detect+audit+emit but DO NOT block
    // (shadow rollout), 'off' = pass-through. Overrides the per-control boolean `enabled` when set. Task E3.
    'modes' => [
        'tool_firewall' => env('AI_GUARDRAILS_MODE_TOOL_FIREWALL', 'enforce'),
        'input_screen' => env('AI_GUARDRAILS_MODE_INPUT_SCREEN', 'enforce'),
        'output_handler' => env('AI_GUARDRAILS_MODE_OUTPUT_HANDLER', 'enforce'),
        'hitl' => env('AI_GUARDRAILS_MODE_HITL', 'enforce'),
    ],

    // Pre-screening normalization, applied BEFORE pattern matching to defeat trivial evasion. Task E1.
    // PATTERN AUTHORING: patterns are matched against the casefolded, NFKC-normalized form.
    // Write all patterns in lowercase (or add the /i flag) — case-sensitive patterns without /i
    // will silently miss mixed-case inputs after casefold normalization.
    // NOTE: NFKC folds fullwidth characters (ｉｇｎｏｒｅ → ignore) but does NOT collapse cross-script
    // lookalikes (Cyrillic/Greek/IPA homoglyphs). See Unicode confusables for future hardening.
    'normalization' => [
        'enabled' => env('AI_GUARDRAILS_NORMALIZE', true),
        'nfkc' => true,
        'strip_zero_width' => true,
        'strip_control' => true,
        'casefold' => true,
        'decode_base64_blobs' => false,
        // Maximum prompt length in Unicode code points (not bytes). Prompts exceeding this limit
        // are blocked with verdict 'too_long' before screening. 0 = unlimited.
        'max_prompt_length' => env('AI_GUARDRAILS_MAX_PROMPT_LENGTH', 50000),
    ],

    // Regex safety. Patterns validated at boot; ReDoS guardrails applied. Task E2.
    'pattern_safety' => [
        'validate_at_boot' => true,
        'pcre_backtrack_limit' => 100000,
        'on_match_error' => env('AI_GUARDRAILS_ON_MATCH_ERROR', 'closed'), // closed | open
        'ruleset_version' => env('AI_GUARDRAILS_RULESET_VERSION', 'v1'),
    ],

    // Domain events emitted on every guardrail decision (host hooks SIEM/Slack/PagerDuty). Task E4.
    'events' => [
        'enabled' => env('AI_GUARDRAILS_EVENTS_ENABLED', true),
    ],

    // Audit data hygiene — keep PII/secrets out of the immutable audit table. Task E5.
    'audit_hygiene' => [
        'prompt_storage' => env('AI_GUARDRAILS_AUDIT_PROMPT_STORAGE', 'redact'), // redact | hash | truncate | raw
        'truncate_at' => 2000,
    ],

    // GDPR-compatible retention for the append-only stores. Task E5.
    'retention' => [
        'days' => env('AI_GUARDRAILS_RETENTION_DAYS', 365),
        'strategy' => env('AI_GUARDRAILS_RETENTION_STRATEGY', 'anonymize'), // anonymize | purge | keep
    ],

    // Tool-level authorization (beyond owner-key re-scoping) + match semantics. Task E7.
    'tool_authorization' => [
        'enabled' => env('AI_GUARDRAILS_TOOL_AUTHZ_ENABLED', false),
        'owner_key_depth' => env('AI_GUARDRAILS_OWNER_KEY_DEPTH', 'recursive'), // top_level | recursive
        'destructive_match' => env('AI_GUARDRAILS_DESTRUCTIVE_MATCH', 'exact'), // exact | substring
    ],
];
