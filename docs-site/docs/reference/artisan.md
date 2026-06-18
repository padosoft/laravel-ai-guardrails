---
title: Artisan commands
description: Every console command the package registers.
---

# Artisan commands

## Screening & output

```bash
# Screen a prompt (exits non-zero when blocked); reads STDIN if no argument
php artisan ai-guardrails:screen "please ignore all previous instructions"

# Sanitize + redact a text blob
php artisan ai-guardrails:sanitize "<script>steal()</script> ![x](http://evil/leak)"

# List recent injection-audit attempts (blocked and allowed)
php artisan ai-guardrails:audit --limit=50
```

## Retention (GDPR)

```bash
# Apply the configured retention strategy to the audit table (actor-audited — the only erasure path)
php artisan ai-guardrails:purge --strategy=anonymize --days=365 --actor="ops:nightly"

# Preview what would be affected, change nothing
php artisan ai-guardrails:purge --dry-run
```

| Option | Meaning |
|---|---|
| `--strategy` | `anonymize` \| `purge` \| `keep` (overrides config) |
| `--days` | rows strictly older than now − days (≥ 1 for a mutating run) |
| `--actor` | required for a mutating run; recorded in the audit log |
| `--dry-run` | report counts without modifying |

See [audit hygiene & retention](/guides/retention) for the full semantics.

## HITL setup

```bash
# Run laravel-flow's migrations (flow_runs / flow_approvals) scoped from vendor — idempotent
php artisan ai-guardrails:hitl-install

# Diagnose the HITL setup; non-zero exit until it can actually gate a call
php artisan ai-guardrails:hitl-status
```

## MCP (when enabled)

```bash
# Start the local (stdio) MCP server exposing the guardrail tools
php artisan mcp:start ai-guardrails
```

::: callout info
`ai-guardrails:purge` requires `audit.store=database`. `ai-guardrails:hitl-install` / `hitl-status` require `laravel-flow`. `mcp:start` requires `laravel/mcp` and `mcp.enabled=true`.
:::
