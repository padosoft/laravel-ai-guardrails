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
- [x] **55 tests / 113 assertions** GREEN; pint + phpstan level 8 clean (run phpstan with --memory-limit=512M locally; 128M OOMs — not a real error).
- [x] PR #4 review: screener now **fails CLOSED** on `preg_match` error (pulled forward from E2); audit store respects the master kill-switch (off → Null); append-only builder blocks mass update+delete; docblock "PCRE regex" only.
- [ ] DoD loop → PR. Then E1 (normalization) + E2 (ReDoS/fail-closed) harden the same screener on this Control B branch.

### Next
- After Control B base merges: E1 (prompt normalization + length ceiling), E2 (regex safety / fail-closed / ruleset version) — harden the screener (the audit is the value, but normalization closes the trivial-bypass gap).

### Roadmap macro status
- [ ] Task -1 governance · [ ] Task 0 scaffold · [ ] Task 1 facade/core · [ ] Task 2 Control A · [ ] Task 3 Control B · [ ] Task 4 Control C · [ ] Task 5 Control D · [ ] Task 6 Artisan · [ ] Task 7 arch tests · [ ] Task 8 README/docs · [ ] Tasks 9–18 HTTP API · [ ] E1–E9 hardening · [ ] E9-API · [ ] E10 release.
