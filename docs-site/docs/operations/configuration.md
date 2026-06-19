---
title: Configuration reference
description: Every config block in config/ai-guardrails.php.
---

# Configuration reference

Everything is a toggle in `config/ai-guardrails.php`. The four controls are **on by default**; the HITL bridge, HTTP API, and MCP surface are **default-OFF**. A master kill-switch sits on top.

## Master & controls

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | master kill-switch — off degrades every control to pass-through |
| `tool_firewall.owner_keys` | `user_id, owner_id, account_id, customer_id` | argument keys the model may never choose |
| `tool_firewall.reject_unknown_arguments` | `true` | reject args not declared in the tool schema |
| `input_screen.patterns` | (4 built-in) | `ruleId => PCRE pattern` |
| `output_handler.html_mode` | `escape` | `escape` or `allowlist` (HTMLPurifier when installed) |
| `output_handler.redact_pii` | `true` | redact PII via `laravel-pii-redactor` when present |
| `output_handler.sanitize_tool_calls` | `false` | opt-in defense-in-depth over tool-call arguments |
| `hitl.enabled` | `false` | enable the HITL approval bridge |
| `hitl.destructive_tools` | `refund, delete, send_email` | tool names treated as destructive |
| `hitl.fallback` | `deny` | when approval is unavailable: `deny` or `pass` |

## Modes & normalization

| Key | Default | Purpose |
|---|---|---|
| `modes.{tool_firewall,input_screen,output_handler,hitl}` | `enforce` | per-control `enforce` \| `monitor` \| `off` |
| `normalization.fold_confusables` | `true` | fold cross-script homoglyphs before matching |
| `normalization.max_prompt_length` | `50000` | max prompt length in code points (0 = unlimited) |
| `pattern_safety.on_match_error` | `closed` | `closed` blocks on a PCRE error, `open` skips the rule |
| `pattern_safety.ruleset_version` | `v1` | stamped on every verdict + audit row |

## Authorization & hygiene

| Key | Default | Purpose |
|---|---|---|
| `tool_authorization.enabled` | `false` | gate tool use behind a Laravel Gate ability (fail-closed) |
| `tool_authorization.ability` | `ai-guardrails:use-tool` | the Gate ability checked with the tool class |
| `tool_authorization.owner_key_depth` | `recursive` | `recursive` or `top_level` re-scoping |
| `audit_hygiene.prompt_storage` | `redact` | `redact` \| `hash` \| `truncate` \| `raw` |
| `retention.strategy` | `anonymize` | `anonymize` \| `purge` \| `keep` |
| `retention.days` | `365` | retention window for `ai-guardrails:purge` |

## Stores (all default `null`)

| Key | Values | Notes |
|---|---|---|
| `audit.store` | `null` \| `array` \| `database` | Swept by `ai-guardrails:purge` |
| `firewall_log.store` | `null` \| `array` \| `database` | |
| `output_stats.store` | `null` \| `array` \| `database` | |
| `hitl_requests.store` | `null` \| `array` \| `database` | Append-only HITL request sidecar; swept by `ai-guardrails:purge` (v1.1.0) |
| `settings.store` | `config` \| `database` | |
| `settings_audit.store` | `null` \| `array` \| `database` | |

All store keys and their `table`/`connection` sub-keys are **infrastructure-only** — they cannot be overridden at runtime via `PUT /settings` (env/config only).

## Surfaces

| Key | Default | Purpose |
|---|---|---|
| `events.enabled` | `true` | dispatch domain events |
| `api.enabled` | `false` | the default-OFF HTTP admin API |
| `mcp.enabled` | `false` | the default-OFF MCP server surface |

## Runtime overrides

When `settings.store=database`, allow-listed keys can be changed at runtime via `PUT /settings`; the provider overlays them onto live config at boot (effective next boot). Every change is recorded to the [settings-change audit](/guides/events). See the [HTTP API](/operations/http-api).

The allow-list covers all per-control enabled/mode booleans and enums, `input_screen.patterns`, `hitl.destructive_tools`, all `normalization.*` sub-toggles (`nfkc`, `strip_zero_width`, `casefold`, `decode_base64_blobs`, `fold_confusables`, `max_prompt_length`), `retention.days`/`strategy`, and `audit_hygiene.prompt_storage`. Infrastructure store keys are never overridable.

::: callout info
Nested config defaults are **not** recursively restored by Laravel's package merge — if you override a block partially, supply every key you care about, or rely on the documented fail-closed defaults.
:::
