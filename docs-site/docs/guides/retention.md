---
title: Audit hygiene & GDPR retention
description: Keep PII out of the immutable audit, and erase it through the one sanctioned path.
---

# Audit hygiene & GDPR retention

The append-only audit is the product — but it captures raw prompts, which can contain PII or secrets. Two mechanisms reconcile "immutable forensic record" with "data hygiene + right to erasure".

## Hygiene: transform on write

`audit_hygiene.prompt_storage` chooses how the prompt is transformed **before** it is persisted:

| Mode | Effect |
|---|---|
| `redact` (default) | compose `laravel-pii-redactor` to strip detected PII |
| `hash` | store only `sha256:…` — identical prompts still correlate, no content kept |
| `truncate` | keep the first `truncate_at` Unicode code points |
| `raw` | store verbatim |

Hygiene is applied at the **store boundary**, so every append path (middleware *and* the `ai-guardrails:screen` command) is covered. When the prompt is transformed, the byte-offset matched-span is dropped (it no longer aligns). An unrecognised mode fails safe to `redact` — never `raw`.

```mermaid
flowchart LR
    A[InjectionAttempt] --> H{prompt_storage}
    H -->|redact| R[PII removed]
    H -->|hash| S[sha256 only]
    H -->|truncate| T[first N code points]
    H -->|raw| V[verbatim]
    R & S & T & V --> DB[(append-only audit)]
```

## Retention: the one erasure path

The audit models throw on UPDATE/DELETE, so erasure goes through the sanctioned, **actor-audited** `ai-guardrails:purge` command — the only place rows leave the table:

```bash
# Anonymize rows older than the retention window (null prompt + principal), keep the counts:
php artisan ai-guardrails:purge --strategy=anonymize --days=365 --actor="ops:nightly"

# Or hard-delete:
php artisan ai-guardrails:purge --strategy=purge --days=365 --actor="ops:nightly"

# Preview without changing anything:
php artisan ai-guardrails:purge --dry-run
```

| Strategy | Effect on audit table | Effect on HITL sidecar |
|---|---|---|
| `keep` | retain indefinitely (no-op) | retain indefinitely (no-op) |
| `anonymize` | null the `prompt` + `principal_id` of rows older than `retention.days` | redact `arguments` to `{}` + null `principal_id`; keep `tool`, `run_id`, `occurred_at` |
| `purge` | hard-delete rows older than `retention.days` | hard-delete rows older than `retention.days` |

The command uses the **raw query builder** to bypass the immutable models — keeping the append-only invariant true for every other code path. A mutating run requires `--actor` and `--days >= 1` and logs the actor, strategy, cutoff, and affected-row count **per table**.

### Which tables are swept

The command sweeps every table whose store is set to `database`:

- **`audit.store = database`** → sweeps `ai_guardrails_injection_audit`
- **`hitl_requests.store = database`** → sweeps `ai_guardrails_hitl_requests`

At least one must be `database`; the command errors only if **neither** is on database. Both can be swept in the same run.

### Sidecar anonymize — raw arguments by design

The HITL request sidecar stores the **scoped** tool arguments verbatim so approvers can see exactly what will execute. Under `anonymize`, `arguments` is redacted to `{}` and `principal_id` is nulled, but `tool`, `run_id`, and `occurred_at` are preserved — the approval audit trail remains; the PII is gone. Under `purge`, the entire row is deleted.

::: callout warning
- `--actor` is **mandatory** for a mutating run (omit only with `--dry-run`) — erasure must be accountable.
- `--days=0` is rejected for a mutating run (its cutoff would match every row). Use `--dry-run` to preview a days-0 count.
- Schedule it (e.g. a nightly cron) with a fixed `--actor` like `scheduler` so every run is attributable.
:::
