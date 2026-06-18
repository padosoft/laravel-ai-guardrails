---
title: Best practices
description: Field-tested guidance for deploying the guardrails.
---

# Best practices

## Rollout

::: steps

1. **Start in `monitor`.** Deploy each control in [monitor mode](/concepts/modes), watch the would-have-blocked stream via `GET /audit` and the events, and measure your false-positive rate before enforcing.

2. **Enable the database stores.** The audit is the product — point `audit.store=database` (and `firewall_log` / `output_stats` as needed) so you keep the forensic record.

3. **Set audit hygiene.** Default `redact` keeps PII out; choose `hash` if you only need correlation, `raw` only when you control the data.

4. **Wire one event listener** to your SIEM/Slack so blocks and settings changes are visible in real time.

5. **Flip to `enforce`** once the monitor data looks clean.

:::

## Pattern authoring

- Patterns match the **casefolded, normalized** form — write them lowercase or with `/i`.
- Keep patterns anchored and bounded (`\b…\b`, `.{0,40}`) to avoid catastrophic backtracking; `pattern_safety` bounds the backtrack limit and fails closed, but tight patterns are cheaper.
- Bump `pattern_safety.ruleset_version` when you change the rules — it is stamped on every verdict and audit row, so you can correlate detections with the ruleset that produced them.

## Tools

- Wrap **every** tool with `guard()` — it is a no-op when disabled, so there's no cost to always-on wrapping.
- For destructive tools, also `routeForApproval()` and restrict `hitl.allowed_tool_classes` to those FQCNs.
- Turn on `tool_authorization.enabled` **only after** defining the Gate ability — it fails closed.

## Operations

- Schedule `ai-guardrails:purge` with a fixed `--actor` (e.g. `scheduler`) for GDPR retention; keep it `--dry-run` in staging.
- Protect the HTTP API with real auth middleware — it exposes audit data and lets an operator change security settings. An empty middleware stack throws at boot by design.
- Mirror the CI [mutation gate](/operations/observability) locally with Docker if you change the security logic.

## Anti-patterns

::: callout warning
- **Don't** trust the model's owner arguments — that's the whole point of Control A.
- **Don't** forward the full `InjectionAttempt`/event payload to a third-party webhook — it carries the raw prompt.
- **Don't** run firewalled tools before authentication — a null principal with an owner key fails closed.
- **Don't** treat `monitor` as protection for Control D — destructive calls execute in monitor.
:::
