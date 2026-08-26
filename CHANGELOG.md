# Changelog

All notable changes to `padosoft/laravel-ai-guardrails` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [Semantic Versioning](https://semver.org/).

---

## v1.4.0 — 2026-08-26

Theme: **a fifth control** — Control P asks what the model was READING when it decided to call a tool, where Control D asks which tool it was.

### Added

- **Control P — Provenance Gate (default-OFF)**: refuses a tool call the model decided on *while reading material nobody in the organisation wrote*. The attack it closes needs no jailbreak: somebody emails your support address, the mail is ingested and indexed, retrieval later pulls it in as grounding for an unrelated question from an unrelated user, and the model — which cannot distinguish the document it was asked to read from the operator who asked — follows the instruction inside it. Control A validated the arguments and found them well-formed, because they were.
  - `Provenance\ProvenanceTier` — `trusted_internal` / `untrusted_external` / `machine_generated`, deliberately the same three tiers AskMyDocs assigns at ingest, so a labelled corpus needs no translation layer between two definitions of "trusted".
  - `Contracts\GroundingProvenance` — one method, implemented by the host, because only the host knows its own retrieval pipeline. `Provenance\NullGroundingProvenance` (the default binding) and `Provenance\RequestGroundingProvenance` (a per-invocation accumulator) ship with it.
  - `Provenance\ProvenanceGatedTool` — applied by `AiGuardrails::guard()` OUTSIDE the firewall and the authorizer, so the question is settled before the arguments are worth validating. Its refusal message deliberately does not name the source: naming it would confirm to whoever planted the content that it is in the corpus, a free oracle for tuning the next attempt.
  - `Events\UntrustedGroundingToolGated` — carries the offending tiers and `$blocked`, and fires identically in `monitor`, so an operator can size the impact on real traffic before flipping the switch. No tool arguments are carried: they are the model's, which is to say possibly the attacker's, and this event lands in logs.
  - Config: `provenance.enabled` (default `false`), `provenance.gating_tiers` (default `['untrusted_external']`), and `modes.provenance`. `machine_generated` is opt-in rather than default, because gating a summary of your own handbook would block most real agents on day one — which is how a control ends up switched off for good. An unrecognised tier name is dropped rather than fatal; a list that parses to nothing leaves the control inert rather than gating on an invented default.

### Changed

- The README and the doc site now say **five controls**, not four. Control P is a peer of A–D, not a sub-feature of D.

### Known limitation (documented, not buried)

- Control P **fails open** when nothing is labelled, and that is a deliberate trade rather than an oversight: a fail-closed default would gate every tool call in every app on the day they upgraded. The consequence is worth stating plainly — enabling it without host-side labelling buys precisely zero. The work is the labelling; this control is the part that was easy.
- `RequestGroundingProvenance` accumulates and does not know when one invocation ends. In a queue worker, `reset()` between jobs is the caller's responsibility; without it one email-derived chunk gates every later job in that worker's lifetime. That fails toward *more* gating, so it is safe, but it looks like a malfunction.

### Compatibility

No existing behaviour changed: the control is default-OFF and the default provenance source reports nothing, so all 551 pre-existing tests pass unmodified.

---

## v1.1.0 — 2026-06-19

Theme: **admin-enabling additive API** — all changes are backward-compatible additive fields and new infrastructure; no existing behaviour changed.

### Added

#### HTTP API — GET /overview
- `controls[].posture` (`string`): human-readable posture label derived from the effective mode — `"Engaged"` (enforce), `"Observing"` (monitor), `"Disabled"` (off or disabled).
- `controls[].spark` (`int[12]`): 12-bucket hourly histogram (bucket 11 = current UTC hour).
- `totals.observed_24h` (`int`): count of monitor-mode matches in the last 24 hours (`blocked = false AND rule_id IS NOT NULL`).
- `totals.pending_approvals` (`int`): count of currently pending HITL approvals; `0` when HITL unavailable.

#### HTTP API — GET /audit/trend
- `points[].observed` (`int`): per-day count of monitor-mode matches (`blocked = false AND rule_id IS NOT NULL`). This is a **purely additive subset of `allowed`** (`observed ⊆ allowed`). The **v1.0 invariant `total === blocked + allowed` is preserved**; `allowed` retains its v1.0 meaning (every non-blocked attempt, including monitor-mode matches). Consumers wanting the disjoint "no rule matched" series compute `allowed - observed`.

#### HTTP API — GET /output/stats
- `counts.pii.by_detector` (`object`): map from detector name to redaction count (e.g. `{"email": 3, "phone": 2}`). Empty object `{}` when no per-detector data is available. `counts.pii_redaction` (the total) is unchanged.

#### HTTP API — GET /approvals
- `tool` (`string`): the tool name parked for approval. Empty string when no sidecar row exists.
- `arguments` (`object`): the **scoped** arguments as rewritten by Control A. Empty object when no sidecar row exists. Raw by design — approvers must see the literal execution arguments.
- `requested_ago` (`string`): human-readable relative time since `created_at`.
- `expires_in` (`string|null`): human-readable relative time until `expires_at`, or `null`.

#### HTTP API — PUT /settings (widened allow-list)
The following keys are now runtime-overridable (Task 5 additions):
- `normalization.nfkc`, `normalization.strip_zero_width`, `normalization.casefold`, `normalization.decode_base64_blobs`, `normalization.fold_confusables`, `normalization.max_prompt_length`
- `hitl.destructive_tools` (array of non-empty strings)
- `input_screen.patterns` — must be fully-delimited PCRE strings (e.g. `/\bdrop\b/iu`); bad regex rejects the whole request.

#### HITL request sidecar (`ai_guardrails_hitl_requests`)
- New append-only table (migration stub included) that records `run_id`, `tool`, `arguments` (JSON), `principal_id`, and `occurred_at` at park-time.
- Enabled via `hitl_requests.store = database` (default `null`, off).
- Model is immutable (throws on update/delete/truncate).
- Consumed by `GET /approvals` batch-join on `run_id`.
- Sidecar write is best-effort — a logging failure never un-parks or errors an already-parked approval.

#### `ai-guardrails:purge` — GDPR erasure coverage for the sidecar
- The command now sweeps both `ai_guardrails_injection_audit` (when `audit.store=database`) **and** `ai_guardrails_hitl_requests` (when `hitl_requests.store=database`) in a single actor-audited run.
- `purge` strategy: hard-deletes old sidecar rows via the raw query builder (bypasses the immutable model).
- `anonymize` strategy: redacts `arguments` to `{}` and nulls `principal_id`; keeps `tool`, `run_id`, `occurred_at` (the approval trail survives; PII is gone).
- `keep` strategy: no-op for both tables.
- Command now proceeds if **either** table is on `database`; errors only if neither is.
- Per-table affected counts are reported and logged.
- `--dry-run` previews per-table counts without mutating.

### Changed

- `ai-guardrails:purge` no longer hard-errors when `audit.store != database` — it proceeds if `hitl_requests.store = database`.

### Backward compatibility

All changes are strictly additive. No existing response keys were renamed or removed. No existing config keys changed their defaults. No behaviour changed unless you opt in to a new toggle.

---

## v1.0.0

Every documented limitation closed: cross-script confusables fold, HTMLPurifier-grade allowlist, opt-in tool-call sanitization, turnkey HITL commands, and the MCP surface.

## v0.3.0

Enterprise hardening: enforce/monitor/off modes, domain events, audit hygiene + GDPR retention, settings-change audit, tool authorization, the mutation-testing gate, and overview API deltas.

## v0.2.0

The admin HTTP API surface.

## v0.1.0

The four controls + core scaffolding.
