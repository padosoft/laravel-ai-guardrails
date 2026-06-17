# Lessons

> Pass this file into every new session and every sub-agent. Date entries `YYYY-MM-DD`.

## 2026-06-16

### Environment (this machine)
- PHP 8.5.7 + Composer 2.9.7 are available via **Herd**, NOT in the bash PATH. Use the explicit path `"$env:USERPROFILE\.config\herd\bin\php85.bat"` (PowerShell) / `"$HOME/.config/herd/bin/php85.bat"` (Git Bash), and `composer.bat`. To use bare names, first `export PATH="$PATH:$HOME/.config/herd/bin"` (bash) once per shell.
- GitHub: remote `git@github.com:padosoft/laravel-ai-guardrails.git`, SSH. `gh` is authenticated (verify with `gh auth status`). `copilot` CLI v1.0.63 present.
- `resources/` contains `laravel-ai-guardrails-Banner.png` (banner) + `laravel-ai-guardrails-admin-Dashboard-Dark.png` (screenshot), **committed in Task 0** (`feature/v0.1.0`). Reference these exact filenames in the README at Task 8 (the plan said `banner.png` generically).

### Local dependency clones (for composer path repositories during dev)
- `laravel/ai` → `../AskMyDocs/vendor/laravel/ai` (verified `src/` present).
- `padosoft/laravel-flow` → `../padosoft-laravel-flow`.
- `padosoft/laravel-pii-redactor` → `../padosoft-laravel-pii-redactor`.
- **RESOLVED (Task 0): use Packagist, NOT path repos.** All three deps are published on Packagist (HTTP 200) AND public on GitHub: `laravel/ai` (stable tag `v0.8.1`), `padosoft/laravel-flow` (`v1.0.0`), `padosoft/laravel-pii-redactor` (`v1.2.0`). composer.json uses `"laravel/ai": "^0.8"` (require), flow/pii `^1.0` (require-dev), `minimum-stability: stable` + `prefer-stable: true`, NO `repositories` block → resolves identically locally and on CI. The local clones were only used for the initial API research (the clone was a newer dev branch `dev-feat/v8_15-...`; published stable is v0.8.1).
- **Verify v0.8.1 contracts during Task 2** (the plan's API was read from the dev clone): confirmed `Laravel\Ai\Contracts\Tool` in v0.8.1 = `description(): Stringable|string`, `handle(Request): Stringable|string`, `schema(JsonSchema): array` (matches plan). Still TODO: `Request` ctor, `AgentPrompt`, `AgentResponse`, and `Illuminate\JsonSchema\Types\Type::toArray()` shape against v0.8.1.
- Stack confirmed working together: PHP 8.5.7 + PHPUnit 12.5.30 + Orchestra Testbench 11.x + Laravel framework (pulled by testbench). `illuminate/json-schema` ships inside `laravel/framework`.
- Default screening patterns use the `/u` (unicode) PCRE flag from the start (E1 foresight) — remember byte-vs-char offset implications for the matched-span feature (Task 11).
- `infection/infection` deferred to Task E9 (avoid a PHPUnit 12 peer conflict during scaffolding); the DoD mutation gate activates from E9 onward.

### Governance (ported from product_image_discovery_admin)
- The reference repo keeps governance in `AGENTS.md` + `docs/RULES.md` (+ `.agents/`), not `.claude/`. We use Claude format: `AGENTS.md`, `CLAUDE.md`, `.claude/rules/`, `.claude/skills/`.
- **Copilot reviewer gotcha (important):** request via GraphQL `requestReviewsByLogin` with bot login `copilot-pull-request-reviewer[bot]`. `gh pr edit --add-reviewer @copilot` can fail (needs `read:project`) and REST `reviewers[]=copilot` can 200 without creating a visible review. See `.claude/skills/copilot-review-loop`.
- **EMPIRICALLY VERIFIED on PR #1 (2026-06-16):** the `requestReviewsByLogin`/`botLogins` mutation **works** — it returned `{"data":{"requestReviewsByLogin":{"clientMutationId":null}}}` and the Copilot bot appeared in `requested_reviewers` and actually reviewed the PR. A Copilot PR comment claimed this mutation "does not exist in the public schema" — that claim is **false** (refuted by the successful call). It is an undocumented-but-functional GitHub mutation, also used by the reference repo.
- **SCHEMA CHANGED by PR #14 (2026-06-17): `requestReviewsByLogin` AND `botLogins` are now REJECTED** — `RequestReviewsInput` returns `argumentNotAccepted` for both. **New working method: REST** `gh api --method POST repos/{owner}/{repo}/pulls/{n}/requested_reviewers -f "reviewers[]=copilot-pull-request-reviewer[bot]"`. Verified on PR #14: `requested_reviewers` then shows `{"users":["Copilot"]}` (bot id `BOT_kgDOCnlnWA`, app `copilot-pull-request-reviewer`). The slug MUST be the full `copilot-pull-request-reviewer[bot]` (bare `copilot`/`Copilot` 422s). `suggestedActors(capabilities:[CAN_BE_ASSIGNED])` lists `copilot-swe-agent` (the coding agent), NOT the reviewer — don't use that id.
- Do not use `@codex review` as a Copilot substitute unless explicitly asked.

### Review fixes applied to `feature/v0.1.0` (2026-06-16, Copilot /review)
- **`ScreenVerdict` invariant fix:** made `__construct` `private` so `blocked=true, ruleId=null` is impossible. The only entry points are `allow()` and `block()` (both named constructors). Added `ScreenVerdictTest` asserting constructor is private.
- **Service provider boot guards:** added two checks in `AiGuardrailsServiceProvider::boot()`:
  1. `Log::warning()` when `enabled=true` + `runningUnitTests()===false` + `InjectionScreener` still resolves to `NullInjectionScreener` (stops automatically once real impls are bound). Note: `isEnvironment()` does NOT exist on `Illuminate\Foundation\Application` in Laravel 13 — use `runningUnitTests()` instead.
  2. `throw RuntimeException` when `api.enabled=true` AND `api.middleware===[]` (fail-hard: open API surface is a security misconfiguration). Tested in `ApiGuardBootTest`: set config in test body after app boots, then call `boot()` on a fresh provider — do NOT use `defineEnvironment()` because it fires before boot causing `setUp()` to throw.
- **PHPStan raised to level 8** (`phpstan.neon`). Passes cleanly.
- **`composer.json` scripts** fixed to `vendor/bin/phpunit` etc.
- **`require-dev` ranges** tightened to `orchestra/testbench: ^11.0` and `phpunit: ^12.0` (match CI matrix).
- 10 tests / 20 assertions green; pint clean; phpstan level 8 clean.
- `php85` (bare) won't resolve on Windows; always use `php85.bat` or the full `%USERPROFILE%\.config\herd\bin\php85.bat` path.
- `copilot-review-loop/SKILL.md` was bash-only. Rewrote step 1 as PowerShell-first with a Git Bash alternative. `/tmp/branch.diff` → `$env:TEMP\branch.diff`.
- Hardcoded username `lopad` in `padosoft-package-tdd/SKILL.md` toolchain path replaced with `$env:USERPROFILE` / `$HOME`.
- Infection/MSI gate (`vendor/bin/infection --min-msi=80`) was absent from every DoD checklist despite Rule 20 mandating it. Added to: `AGENTS.md` step 1, `CLAUDE.md` TDD cycle, `padosoft-package-tdd/SKILL.md` step 7, `00-working-method.md` DoD summary.
- AGENTS.md DoD step 1 mentioned "JS/Vite assets" — removed (pure PHP/API package, no JS/Vite).

### PR #1 review fixes (2026-06-16, Copilot + codex-connector, 10 comments)
- Genericized machine-specific identities: bash PATH `/c/Users/lopad/...` → `$HOME/...`; removed the `lopadova` gh username from LESSON/PROGRESS (use `gh auth status`).
- Made every `php85.bat` invocation explicit (`"%USERPROFILE%\.config\herd\bin\php85.bat"` / `& $php`) since Herd PHP is not on PATH — 5 spots across AGENTS/rules/skills.
- Git Bash temp path `$TEMP` → `${TMPDIR:-/tmp}` (Windows `$TEMP` is a backslash path bash redirection mishandles).
- Clarified `resources/` assets are present-but-untracked until Task 8 (avoids broken README links).
- **REFUTED Copilot P1** ("requestReviewsByLogin doesn't exist"): empirically false — the mutation succeeded on PR #1 and added the reviewer. Kept it; see the Environment > Copilot reviewer note above. Lesson: verify review claims against evidence; do not blindly comply.

### PR #2 review fixes (2026-06-16, Copilot + codex on the macro PR — CI all 3 legs green)
- **API guard fail-CLOSED:** Laravel's package config merge does NOT recursively restore nested defaults, so a host partial config like `['api' => ['enabled' => true]]` leaves `config('ai-guardrails.api.middleware')` as `null`, not `[]`. The guard's `=== []` check skipped that case → open API. Fixed to `! is_array($mw) || $mw === []`. Added `test_boot_throws_when_api_enabled_with_null_middleware`. **General lesson: never rely on nested package-config defaults being present; treat missing/non-array as the unsafe state.**
- **illuminate constraint narrowed `^12|^13` → `^13.0`** to match what CI actually tests (don't advertise untested Laravel 12 support). `laravel/ai ^0.8` still allows 12|13 but composer picks 13.
- **`TestCase::resolve()` is `class-string<T>`-typed** — do NOT call it with a container alias string (`'ai-guardrails'`). Resolve aliases via the `app('alias')` helper instead. Split FacadeResolvesTest into a class-string test + an alias test.

### Control A PR #3 review fixes (2026-06-17, codex — 3 real security findings, CI was already green)
- **P1 (real bug): owner-key injection must be schema-restricted.** The scoper injected ALL configured `owner_keys` (default 4) even for tools that declare only a subset → the validator then rejected the injected-but-undeclared keys as "unknown" → **every tool failed under the default config**. Fix: `FirewalledTool` passes the tool's schema property→type map to `scope()`, which only injects owner keys the tool declares. Verified the bug reproduced before the fix.
- **P2a: preserve integer principals.** The scoper hard-cast the principal to `(string)`; an `integer` owner field then failed type validation. Fix: `coerce()` casts to int when the declared type is `integer` (or an integer-only union).
- **P2b: nullable/union types serialize as an ARRAY.** `string()->nullable()->toArray()` → `{"type":["string","null"]}`; `union([...])` likewise. The validator must treat an array `type` as "match ANY member" and recognise `'null'` as a real type (value === null). A scalar `is_string($type)` check silently skipped validation for these.
- General: the scoper↔validator interaction is where firewall bugs hide — always test the decorator end-to-end with the DEFAULT config, not just hand-picked owner keys.

### Control B — laravel/ai v0.8.1 middleware/response contracts (VERIFIED 2026-06-17)
- Agent middleware runs via `Illuminate\Pipeline`: `pipeline()->send($prompt)->through($agent->middleware())->then(fn(AgentPrompt $prompt) => <generate>)`. Signature: `handle(\Laravel\Ai\Prompts\AgentPrompt $prompt, \Closure $next)`. To **refuse without calling the model**, do NOT call `$next($prompt)` — return a fabricated refusal response.
- `AgentPrompt extends Prompt`: parent has `public readonly string $prompt`; AgentPrompt adds `public readonly ?string $invocationId`, `Agent $agent`, `Collection $attachments`, `?int $timeout`. Helpers `contains/prepend/append/revise`.
- Refusal response: `new \Laravel\Ai\Responses\AgentResponse($prompt->invocationId ?? '', $text, new \Laravel\Ai\Responses\Data\Usage(), new \Laravel\Ai\Responses\Data\Meta())`. `AgentResponse.__construct(string $invocationId, string $text, Usage $usage, Meta $meta)`; `$text` is public-mutable (Control C rewrites it). `Usage` + `Meta` have ALL-OPTIONAL ctors (zero/null) — ideal for a no-model refusal.
- Agent declares middleware via `Laravel\Ai\Contracts\HasMiddleware::middleware(): array` (class instances or closures).

### E2 (2026-06-17)
- **`$fillable` silently drops new columns.** `InjectionAuditRecord` uses an explicit `$fillable` list; when E2 added `ruleset_version`, `fill()` silently dropped it (persisted null) until the column was added to `$fillable`. Always assert new audit columns round-trip; update `$fillable` when adding one.
- ScreenVerdict gained `?string $rulesetVersion` via a `withRulesetVersion()` wither (ctor is private). Threaded screener → verdict → middleware → `InjectionAttempt` → audit row (nullable additive column in the stub, unreleased).
- `pattern_safety.on_match_error`: 'closed' (default, block the erroring rule) vs 'open' (skip it). Boot-time `PatternInjectionScreener::validatePatterns()` throws `InvalidScreeningPattern` when `validate_at_boot`.

### Control C security fixes (2026-06-17, post-review remediation)
- **CRITICAL: Reference-style markdown links bypassed sanitization.** `HtmlMarkdownSanitizer` only neutralized inline links `[text](url)`. Reference-link definitions `[label]: https://evil.test` on their own lines were invisible to the inline-link regex — a markdown renderer would still resolve them. Fix: added a multiline regex pass `/^\[[^\]]+\]:\s*\S.*/m` → replaces with `[ref]: (blocked)` before the inline-link pass.
- **HIGH: `data:` and `javascript:` autolinks bypassed the autolink regex.** The old regex required `://` (`[a-z][a-z0-9+.-]*:\/\/`), so `<javascript:alert(1)>` and `<data:text/html,xss>` (which use bare `:`) were not caught. Fix: dropped the `://` requirement to just `:` — the new regex `[a-z][a-z0-9+.-]*:[^\s>]*` catches all URI-scheme autolinks. Backtracking still allows `(?:>|&gt;)` to match the HTML-escaped closing delimiter.
- **MEDIUM: Eager `config()` call in `AiGuardrailsServiceProvider::register()`.** `$outputActive = config(...)` evaluated at registration time when the config repository may not yet be fully bootstrapped. Fix: moved all config reads into the singleton closures (lazy eval). The `OutputSanitizer` and `PiiRedaction` singletons now unconditionally register with closures that read config at resolution time — consistent with the `GuardrailInputMiddleware` pattern. Removed the now-dead default bindings for those two interfaces (their closures handle the PassthroughSanitizer/NullPiiRedaction fallback internally).
- **Compose-not-couple: `use Vendor\Class` is safe.** A `use Padosoft\PiiRedactor\RedactorEngine` alias at the top of `PiiRedactionFactory` / `RealPiiRedaction` does NOT trigger autoloading — PHP only resolves class names when they are instantiated or reflected at runtime. `RedactorEngine::class` is just the FQCN string. Combined with `class_exists(RedactorEngine::class)` guarding all instantiation, the boundary is correctly enforced.
- **`AgentResponse->text` is public-mutable.** `TextResponse::$text` is declared `public string $text` (NOT readonly) in `laravel/ai` v0.8.1. Direct mutation in `GuardrailOutputMiddleware` is valid TODAY; it would break if a future version makes it readonly. Document this dependency in any upgrade notes.


- **HIGH: `pcre.backtrack_limit` was set BEFORE normalization.** The `ini_set` ran before `$normalizer->normalize()`. `UnicodePromptNormalizer` uses `preg_replace` internally; under the lowered limit it could error and return null — silently skipping zero-width/homoglyph stripping and letting evasion chars through to pattern matching. Fixed: moved `ini_set` to after normalization completes, directly before the pattern loop. Also added `try/finally` to restore the previous limit after the loop, preventing the lowered limit from persisting across the rest of the request.
- **HIGH: `pcre.backtrack_limit` was never restored.** After the first screened prompt, the lowered limit persisted for the entire PHP process, silently degrading all other PCRE calls (routing, validation, user code). Fixed with `try { ini_set ... } finally { ini_set pcre.backtrack_limit, $prevLimit }`.
- **MEDIUM: `on_match_error=open` left no audit trace of the skipped rule.** An attacker who forced a specific rule to error (bad UTF-8 that normalization missed, backtrack limit exhaustion) bypassed that rule while the append-only audit showed a clean pass — defeating the "audit is the value" invariant. Fix: collect errored rule IDs in the screener when `open`-skipping; surface them in `ScreenVerdict::$erroredRuleIds` (new field, backward-compat default `[]`); thread through `GuardrailInputMiddleware` → `InjectionAttempt::$erroredRuleIds` → `errored_rule_ids` nullable JSON column in the audit table.
- **MEDIUM (acknowledged limitation): boot validation is syntax-only.** `validatePatterns()` detects malformed patterns but NOT catastrophic backtracking (a valid `/(a+)+$/` passes). Documented this clearly in the `validatePatterns()` docblock. The runtime `pcre_backtrack_limit` is the sole ReDoS bound. Added a test asserting that a known-exponential pattern passes boot validation (documents the limitation as intended behavior).
- **Tests added for all four fixes:** backtrack limit closed → blocks (`PREG_BACKTRACK_LIMIT_ERROR`), backtrack limit open → allows + errored rule in verdict, subsequent non-erroring rules still match after an open-skip, and `pcre.backtrack_limit` is restored after `screen()` returns.
- **Lesson: ini_set side-effects in security components.** Any `ini_set` in a screening/middleware path must be wrapped in try/finally with explicit restore. Never rely on the caller to clean up.


### Decisions
- **No Playwright in this repo** — it is code + HTTP API only; UI/Playwright lives in `laravel-ai-guardrails-admin`. (Per the project rule "se è solo codice non importa".)

### Control A — laravel/ai v0.8.1 JSON Schema model (VERIFIED 2026-06-16, diverges from the plan)
- The object passed to `Tool::schema(Illuminate\Contracts\JsonSchema\JsonSchema $schema)` at runtime is an **`Illuminate\JsonSchema\JsonSchemaTypeFactory`** (it implements the contract with INSTANCE methods `string()/integer()/object()/...`). The `Illuminate\JsonSchema\JsonSchema` class is NOT it — that one only has STATIC `__callStatic`. In the validator, instantiate `new JsonSchemaTypeFactory` to call `$tool->schema(...)`.
- **`Type::toArray()` on a LEAF type does NOT contain `required`.** `string()->required()->toArray()` === `['type' => 'string']`. The plan's `$definition['required']` assumption is WRONG for v0.8.1.
- **Read `required` from the parent object instead:** `$factory->object($schemaMap)->toArray()` yields `{ "properties": {key:{"type":...}}, "type":"object", "required":[<required key names>] }`. So: `properties` = key→leaf-def (has `type`), `required` = list of required key names. This is the public-API way; do NOT reflect on the protected `$required` prop.
- `Laravel\Ai\Tools\Request`: `__construct(protected array $arguments = [])`, `implements Arrayable, ArrayAccess`, `all(mixed $keys=null): array`, `toArray(): array`. Construct new args via `new Request([...])` (no setter).
- Tool contract import: `schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array`, `handle(\Laravel\Ai\Tools\Request): Stringable|string`, `description(): Stringable|string`.
- PHP 8.5 deprecation: `ReflectionProperty::setAccessible()` is deprecated/no-op — don't call it.

### Control A security fixes (2026-06-16, post-review remediation)
- **Null-principal IDOR bypass (HIGH):** `UserScopedArgumentScoper::scope()` originally returned args untouched when `$principalId === null`. Any unauthenticated request with an owner-key argument (e.g. `user_id`) bypassed all IDOR defence. Fixed: throw `\LogicException` when `$principalId === null` AND an owner key is present in the arguments. Safe pass-through only when no owner keys appear (e.g. anonymous context with no owned resources). Updated test to assert the exception; added two safe-null tests.
- **Type validator fail-open (MEDIUM):** `SchemaToolArgumentValidator::matchesType()` had `default => true` so any unrecognised type keyword (e.g. `"null"`, `"date"`, typo `"int"`) silently accepted every value. Changed to `default => false` (fail-closed). Test uses `ReflectionMethod` to cover the `default` arm directly (no factory path produces unknown types in v0.8.1).
- **`tool_firewall.enabled` dead config (MEDIUM):** `AiGuardrailsServiceProvider::register()` never read the `enabled` flag, so `AI_GUARDRAILS_TOOL_FIREWALL_ENABLED=false` had no effect. Fixed: gate the real bindings on the flag; bind `PassthroughArgumentScoper` + `PermissiveToolArgumentValidator` (new null-object classes) when false. Both-states tests added to `FirewallBindingsTest` (re-instantiates the provider to pick up the config change).
- **`PermissiveToolArgumentValidator` / `PassthroughArgumentScoper`:** created as null-objects in `src/Firewall/`. Both are `final` classes with trivial implementations.


- `padosoft/laravel-flow` config keys to enable persistence + lock store in Testbench, migration dir name, `FlowRun->id` property.
- Screener matched-span byte offsets (PCRE without `/u` = byte offsets; with `/u` careful with multibyte).
- Trend `GROUP BY` day dialect (sqlite/mysql `substr`+`blocked=1` vs Postgres `to_char`/`case when`).

### E1 normalization review fixes (2026-06-17)
- **HIGH: TAG block (U+E0000–U+E007F) not stripped.** These invisible characters were actively used in 2024 invisible-text prompt injection attacks. Added to the zero-width strip regex alongside soft hyphen (U+00AD) and combining grapheme joiner (U+034F). Tests added for all three.
- **MEDIUM: NFKC only covers fullwidth, NOT cross-script homoglyphs.** Cyrillic а (U+0430), Greek ο (U+03BF), and IPA lookalikes are NOT collapsed by NFKC. Documented in the class docblock and in the config as a known gap. Future hardening: Unicode confusables / skeleton algorithm.
- **MEDIUM: `preg_replace() ?? $text` was fail-open.** Changed to an explicit null check with `Log::warning()` when a normalization regex fails; the skipped-pass is logged so operators can detect it. The static patterns make real-world failure virtually impossible, but fail-open posture is unacceptable in a security component.
- **CORRECTNESS: `max_prompt_length` is code points (mb_strlen), not bytes.** Documented in the config comment. Appropriate unit for token-exhaustion concern; a byte-thinking operator who sets a low limit gets a tighter gate than intended.
- **CORRECTNESS: Casefold breaks case-sensitive operator patterns.** Patterns without `/i` that previously matched mixed-case input will miss it after casefold. Documented in the class docblock and config comment — operators must write patterns in lowercase or add `/i`.

### Control D security fixes (2026-06-17, post-review remediation)
- **CRITICAL: Plain-text approval token was echoed to the model.** `ApprovalGatedTool::handle()` returned the `$pending->token` in the response string. Any conversation log or relay by the model exposed the credential. Fixed: response now returns only the non-secret `$pending->runId`. Token never leaves the flow/DB layer.
- **CRITICAL: `ToolApprovalHandler` had no allowlist.** Any Tool class that `class_exists()` + `instanceof Tool` could be dispatched post-approval. If the flow DB row (which stores `tool_class`) is writable by an attacker, arbitrary Tool invocation becomes possible. Fixed: added `hitl.allowed_tool_classes` config (default `[]` = no restriction). When non-empty, only listed FQCN classes may execute; anything else returns `FlowStepResult::failed()`.
- **HIGH: `principal_id` was stored in flow context but never surfaced post-execution.** The approval handler ran the tool without recording who the action was performed on behalf of, making the audit trail incomplete. Fixed: `principal_id` is now included in `FlowStepResult::success()` output so the host's audit log can record it.
- **MEDIUM: `router->route()` RuntimeException caused an unhandled throw in the AI pipeline.** If laravel-flow fails to issue a token (misconfiguration, version mismatch), the exception propagated out of `handle()`. Fixed: wrapped in try/catch; fall-safe to the deny string rather than throwing.
- **LOW: Invalid `$fallback` value accepted silently.** Any string other than `'deny'`/`'pass'` produced no error and quietly fell through to deny. Fixed: `InvalidArgumentException` thrown at construction time.
- **Tests added for all five fixes:** token-not-in-response, allowlist-deny, allowlist-pass, empty-allowlist-pass, route-exception→deny, invalid-fallback-throws. 5 new tests / 10 new assertions.
