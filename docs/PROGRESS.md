# Progress

> Resume point after any interruption. Date entries `YYYY-MM-DD`.

## 2026-06-16

### Task -1 — Governance bootstrap (IN PROGRESS, branch `feature/governance-bootstrap`)
- [x] Environment recon: Herd PHP 8.5.7, Composer 2.9.7, gh authenticated, copilot CLI, remote origin set, dependency clones located, banner+screenshot present in `resources/`.
- [x] Wrote `AGENTS.md`, `CLAUDE.md`, `.claude/rules/{00-working-method,10-padosoft-conventions,20-security-posture}.md`, `.claude/skills/{copilot-review-loop,padosoft-package-tdd}/SKILL.md`, `docs/LESSON.md`, `docs/PROGRESS.md`.
- [x] Local Copilot review run + fixes applied + committed.
- [x] Pushed; **PR #1** `feature/governance-bootstrap → main`; Copilot reviewer requested via GraphQL (confirmed).
- [x] Copilot/codex PR review received; all actionable comments resolved (the recurring GraphQL "P1" is empirically refuted — see LESSON; kept as-is).
- [ ] AWAITING: final review pass clean → **merge PR #1**, then start Task 0. (No CI on this docs-only branch — CI workflow arrives in Task 0.) Resume = `gh pr view 1 --json reviewDecision,comments`; if clean, `gh pr merge 1 --squash`.

### Task 0 — Package scaffold (DONE locally, branch `feature/v0.1.0`)
- [x] composer.json (Packagist resolution, no path repos — see LESSON), pint.json, phpstan.neon, phpunit.xml, .gitignore, .gitattributes.
- [x] config/ai-guardrails.php (full: 4 controls + audit/firewall/output/settings/api + ENTERPRISE HARDENING blocks modes/normalization/pattern_safety/events/audit_hygiene/retention/tool_authorization).
- [x] src/AiGuardrailsServiceProvider.php (skeleton: mergeConfigFrom + publish), tests/TestCase.php, tests/Feature/PackageBootsTest.php.
- [x] .github/workflows/ci.yml (PHP 8.3/8.4/8.5 × Laravel 13: validate → pint → phpstan → phpunit).
- [x] composer update OK (laravel/ai v0.8.1, flow v1.0.0, pii v1.2.0 from Packagist). phpunit GREEN (2 tests), pint passed, phpstan no errors, composer validate --strict OK.
### Task 1 — Facade + core service shell (DONE + review-fixed, branch `feature/v0.1.0`)
- [x] Contracts `InjectionScreener`/`OutputSanitizer`/`PiiRedaction`, `Screening/ScreenVerdict`, null impls (`NullInjectionScreener`, `NullPiiRedaction`, `PassthroughSanitizer`), `AiGuardrails` core service, `Facades/AiGuardrails`.
- [x] Provider binds the three contracts (null-object) + `AiGuardrails` singleton + `ai-guardrails` alias.
- [x] `FacadeResolvesTest` green. Full suite: 5 tests / 10 assertions GREEN; pint passed; phpstan no errors.
- [x] Copilot /review pass applied: `ScreenVerdict.__construct` made private (invariant fix), boot guards added (null-obj warning + API open-surface throw), PHPStan → level 8, composer scripts + require-dev ranges fixed. 10 tests / 20 assertions green; phpstan level 8 clean.
- [ ] DoD loop for macro `feature/v0.1.0` (= Task 0 + Task 1): push → PR → main → Copilot reviewer → CI green → merge.

### Task 2 — Control A: Tool Firewall (DONE locally, branch `feature/control-a-tool-firewall`)
- [x] Verified laravel/ai v0.8.1 JSON Schema model (see LESSON "Control A"): `Type::toArray()` leaf has NO `required`; read required via `object($map)->toArray()['required']`. Runtime schema arg is `JsonSchemaTypeFactory`.
- [x] `Contracts/{ArgumentScoper,ToolArgumentValidator}`, `Firewall/{UserScopedArgumentScoper,SchemaToolArgumentValidator,FirewalledTool}`, `Exceptions/ToolArgumentRejection`, `Doubles/FakeOwnedTool`.
- [x] Provider binds `ArgumentScoper`/`ToolArgumentValidator` from `tool_firewall` config (owner_keys, reject_unknown — both-states tested).
- [x] Schema-aware scoping (owner-key injection restricted to declared keys; integer principal coercion), union/nullable validation, array/object distinction, master kill-switch degrades firewall. Full suite **38 tests / 74 assertions** GREEN; pint + phpstan level 8 clean.
- [x] DoD loop: local + PR review (2 rounds, codex P1 owner-key bug + Copilot array/object + master-switch) all resolved; CI green on PR #3.

### Task 3 — Control B: Input middleware + append-only injection audit (DONE locally, branch `feature/control-b-input-screen`)
- [x] Verified laravel/ai v0.8.1 middleware contracts (LESSON "Control B"): refuse = return `new AgentResponse($invocationId??'', $text, new Usage, new Meta)` without calling `$next`.
- [x] `PatternInjectionScreener` (config regex, /u, first-match-wins), `GuardrailInputMiddleware` (screen→audit every attempt→refuse-without-model OR delegate).
- [x] Append-only audit: `InjectionAttempt` DTO, `InjectionAuditStore` contract, Null/Array/Database stores, immutable `InjectionAuditRecord` model (update/delete throw), migration stub (no updated_at), publishing.
- [x] Provider binds screener (Pattern when input_screen+master enabled, else Null), audit-store factory (null|array|database), middleware (principal via auth()->guard()->id()).
- [x] **59 tests / 118 assertions** GREEN; pint + phpstan level 8 clean (run phpstan with --memory-limit=512M locally; 128M OOMs — not a real error).
- [x] PR #4 review: screener now **fails CLOSED** on `preg_match` error (pulled forward from E2); audit store respects the master kill-switch (off → Null); append-only builder blocks mass update+delete; docblock "PCRE regex" only.
- [ ] DoD loop → PR. Then E1 (normalization) + E2 (ReDoS/fail-closed) harden the same screener on this Control B branch.

### Next
- After Control B base merges: E1 (prompt normalization + length ceiling), E2 (regex safety / fail-closed / ruleset version) — harden the screener (the audit is the value, but normalization closes the trivial-bypass gap).

### Roadmap macro status
- [x] Task -1 governance (PR #1) · [x] Task 0+1 scaffold/core (PR #2) · [x] Task 2 Control A (PR #3) · [x] Task 3 Control B (PR #4) · [ ] E1 normalization · [ ] E2 regex-safety · [ ] Task 4 Control C · [ ] Task 5 Control D · [ ] Task 6 Artisan · [ ] Task 7 arch tests · [ ] Task 8 README/docs · [ ] Tasks 9–18 HTTP API · [ ] E3–E9 hardening · [ ] E9-API · [ ] E10 release.

### Task E1 — Prompt normalization + length ceiling (DONE locally, branch `feature/control-b-input-screen-e1`)
- [x] `PromptNormalizer` contract + `UnicodePromptNormalizer` (NFKC via intl with graceful fallback, zero-width strip, control strip, unicode casefold; each pass toggleable) + `NullPromptNormalizer`.
- [x] `PatternInjectionScreener` normalizes before matching (catches zero-width / fullwidth-homoglyph evasion) and blocks over-length prompts as verdict `too_long` (length checked on the original). Normalizer (nullable) + maxPromptLength (int, default 0) are optional trailing ctor params (back-compat).
- [x] Provider binds `PromptNormalizer` (Unicode when normalization.enabled, else Null) and wires it + max_prompt_length into the screener. Both-states tested.
- [x] composer: `ext-mbstring` required; `ext-intl` suggested (NFKC graceful fallback). mb_* calls pass explicit 'UTF-8'.
- [x] **73 tests / 135 assertions** GREEN (incl. TAG-block/soft-hyphen/CGJ stripping from review); pint + phpstan level 8 clean.
- [ ] DoD loop → PR. **Deviation from plan:** over-length is a `too_long` ScreenVerdict (auditable, matches admin v2 verdict union) instead of a thrown `PromptTooLong` exception — cleaner + consistent with the verdict model.

### Task E2 — Regex safety (DONE locally, branch `feature/control-b-input-screen-e2`)
- [x] ruleset_version stamped on every ScreenVerdict (withRulesetVersion wither) → threaded to InjectionAttempt + audit row (nullable column in stub + model $fillable + store read/write).
- [x] boot-time pattern validation: `PatternInjectionScreener::validatePatterns()` + provider boot throws `InvalidScreeningPattern` when validate_at_boot (toggleable).
- [x] configurable `on_match_error` (closed=block / open=skip), `pcre.backtrack_limit` set before matching.
- [x] **Post-review security fixes (2026-06-17):** (1) moved `ini_set` to AFTER normalization so the normalizer's `preg_replace` calls are never throttled; (2) added `try/finally` to restore `pcre.backtrack_limit` — no longer persists across the request; (3) errored rule IDs tracked in open mode → `ScreenVerdict::$erroredRuleIds` → `InjectionAttempt::$erroredRuleIds` → `errored_rule_ids` nullable JSON column (audit bypass now forensically visible); (4) `validatePatterns()` docblock clarified: syntax-only, not ReDoS detection.
- [x] **87 tests / 157 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] DoD loop → PR. Control B (Tasks 3+E1+E2) then complete.

### Task 4 — Control C: Output handler (DONE locally, branch `feature/control-c-output-handler`)
- [x] `HtmlMarkdownSanitizer` (escape HTML + defang markdown link/image + angle autolinks), `StructuredOutputValidator` (validate structured output vs schema; union/nullable), `RealPiiRedaction` (composes pii-redactor `RedactorEngine`) + `NullPiiRedaction`, `GuardrailOutputMiddleware` (rewrites `$response->text` = sanitize→redact; tool_calls untouched).
- [x] `PiiRedactionFactory` keeps the optional-vendor reference inside src/Output (compose-not-couple boundary); provider binds sanitizer + PII + middleware (both-states + master + graceful PII via class_exists). TestCase registers `PiiRedactorServiceProvider` so the engine resolves (require-dev).
- [x] **113 tests / 196 assertions** GREEN; pint + phpstan level 8 clean. Review hardening: structured-response field sanitization, sanitizer fail-closed, indented reference-link defang, StructuredOutputValidator rejectUnknown, PiiRedactionFactory resolve-guard.
- [ ] DoD loop → PR. Then E8 (allowlist mode + URI scheme filter), Control D, etc.

### Task E8 — Output allowlist mode + tool-calls note (DONE locally, branch `feature/control-c-e8`)
- [x] `HtmlMarkdownSanitizer` `html_mode` = 'escape' (default) | 'allowlist' (keep a tiny safe inline-tag set, strip ALL attributes + other tags + links). Provider wires `output_handler.html_mode`.
- [x] Documented + tested limitation: middleware rewrites text/structured only; **tool_calls pass through untouched** (governed by Controls A/D).
- [x] **117 tests / 207 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] PR → CI → merge. (URI-scheme handling already covered by the Control C link/autolink defang; no separate filter needed — noted.)

### Task 5 — Control D: HITL approval bridge (DONE locally, branch `feature/control-d-hitl`)
- [x] `ApprovalRouter` contract + `PendingApproval` DTO; `NullApprovalRouter` (unavailable); `ApprovalGatedTool` decorator (parks destructive call → "pending approval" string; fallback deny/pass when unavailable); `ToolApprovalHandler` (flow step that runs the tool on approval); `FlowApprovalRouter` (adapter over Flow::define/execute/resume/reject + approvalGate).
- [x] `ApprovalRouterFactory` keeps the laravel-flow reference inside src/Hitl; provider binds ApprovalRouter (Flow when hitl.enabled+master+flow-present, else Null). Both-states tested.
- [x] **Security fixes applied (2026-06-17):** token leakage (runId only in response), ToolApprovalHandler allowlist (`hitl.allowed_tool_classes`), principal_id in step output, RuntimeException safe-catch, invalid-fallback guard.
- [x] **135 tests / 242 assertions** GREEN; pint + phpstan level 8 clean.
- LESSON: laravel-flow classes (FlowRun/FlowDefinitionBuilder) are `final` → cannot Mockery-mock; test the adapter via `Flow::swap(<plain anonymous spy>)`. Full flow persistence integration is the host's setup (plan Assumption #2).
- [ ] DoD loop → PR. Then Task 6 (Artisan + composing helper).

### Task 6 — Artisan + composing helpers (DONE locally, branch `feature/task-6-artisan`)
- [x] `AiGuardrails` gains `guard()` (wrap with FirewalledTool), `routeForApproval()` (wrap with ApprovalGatedTool), `isDestructive()`, `validateStructured()`. Master-off → wrappers return the tool untouched. Provider builds it with all collaborators + config.
- [x] Console: `ai-guardrails:screen` (screen+audit a prompt), `ai-guardrails:sanitize`, `ai-guardrails:audit` (list recent). Registered when runningInConsole.
- [x] **145 tests / 261 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] DoD loop → PR. Then Task 7 (architecture tests + master-toggle both-states).

### Task 7 — Architecture tests + master toggle (DONE locally, branch `feature/task-7-arch-tests`)
- [x] `ConventionsTest`: contracts are interfaces; FirewalledTool/ApprovalGatedTool implement Tool; audit stores implement the contract; **compose-not-couple boundary auto-verified** (scan src/ — laravel-flow only in src/Hitl, pii-redactor only in src/Output).
- [x] `MasterToggleTest`: enabled=false degrades every control to pass-through (guard/routeForApproval no-op, screen allows, sanitize passthrough); enabled=true engages.
- [x] **152 tests / 285 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] DoD loop → PR. Then Task 8 (README WOW).

### Task 8 — WOW README + LICENSE (DONE locally, branch `feature/task-8-readme`)
- [x] README: banner (resources/), badges, TOC, "what makes it different", 4-controls table, junior-proof quick start, PHP+Artisan surfaces, middleware wiring, config table, compose section, append-only audit, threat model, known limitations, testing. All commands/config keys match code (R9).
- [x] LICENSE Apache-2.0 copyright filled (Padosoft s.r.l. 2026).
- [ ] DoD loop → PR. Then HTTP API (Tasks 9–18) — the largest chunk; README API section added at Task 18.

### Task 9 + 17 — API foundation + overview + try (DONE locally, branch `feature/task-9-api-foundation`)
- [x] `Http/ApiSchema` (VERSION `ai-guardrails.api.v1` + per-endpoint SCHEMA_* constants), `Http/Support/Envelope` ({schema_version,schema,data}), `routes/ai-guardrails-api.php` (Registrar closure, names `ai-guardrails.api.*`).
- [x] Provider `registerApiRoutes()` — default-OFF gate (api.enabled + bound Registrar + not cached), runs after the open-surface guard.
- [x] `OverviewAggregator` + `OverviewController` (GET /overview); `TryController` (POST /try/screen, /try/sanitize).
- [x] **157 tests / 310 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] DoD loop → PR. Then audit endpoints (10–12), firewall+output stores (13–14), approvals+settings (15–16), API hardening (18), E9-API.

### Tasks 10 + 11 + 12 — Audit HTTP endpoints (DONE locally, branch `feature/task-10-audit-endpoints`, PR #14)
- [x] `InjectionAuditStore` contract extended: `query(AuditQueryFilters): AuditPage`, `find(int): ?InjectionAttempt`, `trend(since, until): list` — implemented in Null / Array (in-memory bucketing, sequential ids assigned on append) / Database (SQL).
- [x] `AuditQueryFilters` DTO (blocked/rule_id/principal_id/q/from/to/limit/cursor; keyset cursor on monotonic id), `AuditPage` DTO (items + nextCursor), `AuditEntryResource` (summary omits principal_id for data-minimization; detail has full prompt + matched_span).
- [x] `AuditController` index/show/trend; routes `GET /audit`, `/audit/trend` (registered before `/audit/{id}` wildcard), `/audit/{id}` (ctype_digit guard → 404).
- [x] `matched_span` byte offset `[start,end)` via PREG_OFFSET_CAPTURE in `PatternInjectionScreener` → `ScreenVerdict::withMatchedSpan` → `InjectionAttempt` → `match_start`/`match_end` columns (migration stub + model $fillable/casts + store write/read).
- [x] trend SQL is dialect-safe (`dayExpression()`: DATE / to_char / CONVERT / strftime); 30-day default window, clamped 366d, inverted-window guard.
- [x] **Review hardening (3 local Copilot rounds, all LGTM):** LIKE metacharacters escaped with `ESCAPE '!'`; strict ISO-8601 date parsing (rejects relative strings); bare-date bounds anchored to UTC midnight regardless of server tz; `blocked` filter uses `FILTER_NULL_ON_FAILURE`.
- [x] **184 tests / 409 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] AWAITING: CI green + Copilot PR review on #14 → auto-merge if clean. Resume = `gh pr view 14 --json reviewDecision,statusCheckRollup,comments`.

### Tasks 10 + 11 + 12 — MERGED (PR #14, squash `b1443d1`, 2026-06-17)
- 6 Copilot review rounds (pg-boolean trend, span-vs-normalized/scrubbed alignment, strict ISO-8601 calendar parsing, array-param/cursor hardening, invalid-UTF-8 scrubbing) → final review clean → auto-merged.

### Task 13 — GET /firewall + FirewallRejectionStore (DONE locally, branch `feature/task-13-firewall-endpoint`)
- [x] Extracted shared `src/Support/AppendOnlyEloquentBuilder.php` (the throwing builder) and refactored `InjectionAuditRecordBuilder` onto it; new `FirewallRejectionRecordBuilder` reuses it (DRY append-only).
- [x] `FirewallRejection` DTO, `FirewallRejectionStore` contract (record/query/count), Null/Array/Database stores, immutable `FirewallRejectionRecord` model, migration stub (no updated_at), `FirewallQueryFilters`/`FirewallPage` (keyset cursor + filters, reuses IsoDateParser + array-param/cursor hardening from Task 12).
- [x] `FirewalledTool` records a `FirewallRejection` (tool, principal, violations, UTC ts) to the store before throwing — store is an optional null-object ctor param (existing call sites unaffected). Threaded through `AiGuardrails::guard()` + provider.
- [x] `FirewallController` (GET /firewall), `FirewallRejectionResource` (bounded tool text + UTF-8 scrub), route `ai-guardrails.api.firewall.index`, provider binds the store (master-switch-aware, null|array|database from `firewall_log` config — already in config since Task 0), publishes the new migration.
- [x] **231 tests / 522 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] DoD loop → PR. Then Task 14 (`GET /output/stats`), 15 (approvals), 16 (settings), 18 (hardening), E3–E7/E9, E9-API, E10.

### Task 13 — MERGED (PR #15, squash `67942f4`, 2026-06-17). 5 Copilot rounds (pg-array JSON, store-failure isolation, violation key/value bounding + collision-safe disambiguation within KEY_LIMIT, LIKE escaping, zero-like cursor) → clean → auto-merged.

### Task 14 — GET /output/stats + OutputStatStore (DONE locally, branch `feature/task-14-output-stats`)
- [x] `OutputStatKind` enum (html_stripped, markdown_sanitized, structured_validation_failure, pii_redaction). `OutputStatStore` contract (record/totals/count), Null/Array/Database stores, immutable `OutputStatRecord` model + builder (shared `AppendOnlyEloquentBuilder`) + migration stub.
- [x] **Reporting sanitizer:** new `ReportingOutputSanitizer` contract + `SanitizationReport` DTO; `HtmlMarkdownSanitizer` now implements it (`sanitizeReport()` tracks html vs markdown change separately; `sanitize()` delegates — behaviour identical). Middleware uses it to count html_stripped/markdown_sanitized + pii_redaction (by before/after compare).
- [x] `AiGuardrails::validateStructured()` records `structured_validation_failure` when violations found. Stores threaded into the middleware + AiGuardrails via optional null-object ctor params.
- [x] `OutputStatsController` (GET /output/stats — zero-filled per-kind counts + total, optional from/to window via IsoDateParser), route `ai-guardrails.api.output.stats`, provider binds the store (master-switch-aware, null|array|database from `output_stats` config — already in config since Task 0), publishes the migration.
- [x] **260 tests / 598 assertions** GREEN; pint + phpstan level 8 clean.
- [ ] DoD loop → PR. Then Task 15 (approvals), 16 (settings), 18 (hardening), E3–E7/E9, E9-API, E10.

### Task 14 — MERGED (PR #16, squash `2637744`, 2026-06-17). 6 Copilot rounds (reserved-word `count`→`event_count`, fire-and-forget stat writes, accurate html_stripped via tag-like regex, safe empty store config, behavior-based test, bounded default window + inverted-window empties) → clean → auto-merged.

### Task 15 — GET /approvals + approve/reject (DONE locally, branch `feature/task-15-approvals`)
- [x] `src/Hitl/ApprovalReadModel.php` — lists pending `flow_approvals` (status=pending) via the real `Padosoft\LaravelFlow\Models\FlowApprovalRecord` when present; `class_exists` guard → [] when flow absent; try/catch → [] when flow installed but tables missing (no 500). Shape: approval_id/run_id/step_name/status/expires_at/created_at (UTC ISO). NOTE: tool name lives on `flow_runs.input`, not the approval row's `payload`, so the list is id-centric (operator approves by the token it holds; host dashboard resolves run details by run_id).
- [x] `ApprovalsController` (GET /approvals list; POST /approvals/{token}/approve|reject). Decision actor's `principal_id` is derived **server-side** (authoritative, client can't spoof); client `actor` accepted only as flat string-keyed scalars. HITL unavailable → 409 (not 500); invalid/expired token → 422 (logged), via try/catch around the router call.
- [x] ApiSchema: `SCHEMA_APPROVAL_LIST` + `SCHEMA_APPROVAL_DECISION` (replaced the unused `SCHEMA_APPROVALS`). Routes `approvals.index/approve/reject`. ApprovalReadModel auto-resolves (no binding needed).
- [x] **281 tests / 674 assertions** GREEN; pint + phpstan level 8 clean. Tests: real-flow integration (park→list→approve resolves; reject; invalid-token 422; fresh-router approve; foreign-flow exclusion), hitl-unavailable (empty list + 409), read-model resilience (tables-missing→[]), ApiGate flag-off 404s. Flow integration migrates the real flow tables manually in setUp (loadMigrationsFrom isn't wired in this harness).
- [ ] DoD loop → PR. Then Task 16 (settings), 18 (hardening), E3–E7/E9, E9-API, E10.

### Next
- Task 16 (`GET/PUT /settings` + GuardrailSettingsStore), Task 18 (API hardening + envelope uniformity test), then E3–E7/E9, E9-API, E10 release.
