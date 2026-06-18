---
title: Architecture Decision Records
description: The non-obvious design decisions, in Problem → Decision → Consequences form.
---

# Architecture Decision Records

The choices below are the ones a reviewer would question. Each is recorded as *Problem → Decision → Consequences*.

::: collapsible open "ADR-1 · Deterministic controls, not a second model"
**Problem.** Prompt-injection defenses are often built as a second "LLM judge".

**Decision.** Controls A–C are pure, offline functions over normalized input. No second model call.

**Consequences.** Reproducible, unit-testable, zero added latency/cost, and auditable. The trade-off: the controls catch *structural* attacks (IDOR, XSS, known injection patterns), not arbitrary semantics — which is why the audit trail is the headline value.
:::

::: collapsible "ADR-2 · Append-only audit vs GDPR erasure"
**Problem.** An immutable audit and a "right to be forgotten" are in tension.

**Decision.** The audit models throw on UPDATE/DELETE; the **only** sanctioned erasure path is the actor-audited `ai-guardrails:purge` command, with `retention.strategy = anonymize | purge | keep`.

**Consequences.** Tamper-evidence by default; GDPR handled by one auditable maintenance command rather than ad-hoc deletes.
:::

::: collapsible "ADR-3 · Audit hygiene keeps PII out of the table"
**Problem.** Storing raw prompts captures whatever PII/secret a user pasted.

**Decision.** `audit_hygiene.prompt_storage = redact | hash | truncate | raw` (default `redact`, composing the PII redactor), applied at the store write boundary so every append path is covered.

**Consequences.** The forensic record stays useful without becoming a PII liability; `raw` remains available when you control the data.
:::

::: collapsible "ADR-4 · Enforce / monitor / off as a per-control dial"
**Problem.** Flipping a guardrail to "block" without knowing its false-positive rate is risky.

**Decision.** Each control has a `monitor` mode that detects + audits + emits but does not block.

**Consequences.** Safe shadow rollout; the `$enforced` flag travels in the event payload so SIEM listeners need no config lookup. See [modes](/concepts/modes).
:::

::: collapsible "ADR-5 · Fail-closed config resolution"
**Problem.** Laravel's package-config merge does not recursively restore nested defaults — a partial host config can leave a nested key `null`.

**Decision.** Treat missing/non-array as the **unsafe** state. An `api.enabled=true` with empty middleware throws at boot; `owner_key_depth` defaults to the *more*-scoping `recursive`.

**Consequences.** Misconfiguration fails loudly or toward more protection, never toward an open surface.
:::

::: collapsible "ADR-6 · Compose-not-couple, enforced by a test"
**Problem.** Optional vendors can leak into the core over time.

**Decision.** Vendor references are confined to adapter dirs (`src/Hitl`, `src/Output`, `src/Mcp`); an architecture test fails the build on any leak. See [compose-not-couple](/architecture/compose-not-couple).
:::

::: collapsible "ADR-7 · Mutation testing as a quality gate"
**Problem.** Green tests do not prove the tests would *catch* a regression.

**Decision.** CI runs Infection at **≥ 80% MSI** over the deterministic security logic (via the standalone PHAR + pcov), excluding pure IO/adapter layers.

**Consequences.** The test suite is proven to kill mutants in the code that matters. See [observability & mutation testing](/operations/observability).
:::

::: collapsible "ADR-8 · Tri-surface discipline"
**Problem.** A capability reachable from code but not from ops (or vice-versa) is half-built.

**Decision.** Every capability is reachable from PHP + Artisan + HTTP API, with MCP as an optional fourth surface; the HTTP API is default-OFF behind a fail-closed middleware guard.

**Consequences.** Consistent reach for developers, operators, and AI clients alike.
:::

::: callout info
These records are summaries; the authoritative rationale and its evolution live in the repository's `docs/LESSON.md` and the PR history, linked from each release.
:::
