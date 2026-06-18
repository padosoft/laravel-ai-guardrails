---
title: laravel-ai-guardrails
description: Deterministic, offline-first prompt-injection guardrails for laravel/ai.
---

![laravel-ai-guardrails banner](https://raw.githubusercontent.com/padosoft/laravel-ai-guardrails/main/resources/laravel-ai-guardrails-Banner.png)

# laravel-ai-guardrails

**Deterministic, offline-first prompt-injection guardrails for [`laravel/ai`](https://github.com/laravel/ai).** Four composable controls that treat *everything the model touches* — its tool arguments, its prompts, and its output — as untrusted.

`laravel/ai` makes it trivial to give a model **tools** (refund an order, delete a record, send an email) and to feed it **untrusted user input**. That is exactly where prompt injection lives. This package closes the gap with **deterministic, offline, unit-testable** controls — no second LLM call, no network, no non-determinism. The audit trail is the product, not a regex you have to trust.

::: callout tip
New here? Jump to the **[Quickstart](/quickstart)** for a five-step setup, or read **[The Four Controls](/controls/overview)** to understand what each layer defends.
:::

## The four controls

::: grids
::: grid
::: card "A — Tool Firewall" icon:lucide-shield-check
Re-scopes model-chosen owner keys (`user_id`, …) to the authenticated principal server-side and validates every argument against the tool's own JSON schema. Closes confused-deputy / IDOR.

[Read →](/controls/tool-firewall)
:::
::: card "B — Input Screening + Audit" icon:lucide-scan-search
Normalizes the prompt (defeating homoglyph / zero-width / case evasion), screens it, refuses before the model runs, and append-only-logs every attempt.

[Read →](/controls/input-screening)
:::
:::
::: grid
::: card "C — Output Handler" icon:lucide-sparkles
Treats the response as untrusted: escapes HTML, neutralizes markdown exfil vectors, validates structured output, redacts PII.

[Read →](/controls/output-handler)
:::
::: card "D — HITL Bridge" icon:lucide-user-check
Routes destructive tool calls through `laravel-flow`'s `approvalGate()` — a human approves before the action runs.

[Read →](/controls/hitl-bridge)
:::
:::
:::

## Why it's different

- **Untrusted-input posture, everywhere.** Tool arguments, prompts, *and* model output are all treated as hostile.
- **Deterministic & offline.** Controls A–C never call a model; every decision is reproducible and testable.
- **Fails closed.** A PCRE error, a tampered flow record, an unresolved engine — every failure path blocks rather than silently allows.
- **Append-only audit.** Every screening attempt (blocked *and* allowed) is logged to an immutable store.
- **Composes, doesn't reinvent.** Optional `laravel-flow`, `laravel-pii-redactor`, `HTMLPurifier`, and `laravel/mcp` — with graceful degradation when absent.
- **Every feature is a toggle**, tested in both states, with a master kill-switch.

## Install

```bash
composer require padosoft/laravel-ai-guardrails
```

Then follow the **[Quickstart](/quickstart)**. Requires PHP `^8.3` and Laravel 13.
