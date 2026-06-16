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
- **EMPIRICALLY VERIFIED on PR #1 (2026-06-16):** the `requestReviewsByLogin`/`botLogins` mutation **works** — it returned `{"data":{"requestReviewsByLogin":{"clientMutationId":null}}}` and the Copilot bot appeared in `requested_reviewers` and actually reviewed the PR. A Copilot PR comment claimed this mutation "does not exist in the public schema" — that claim is **false** (refuted by the successful call). It is an undocumented-but-functional GitHub mutation, also used by the reference repo. Keep using it; do not switch to `requestReviews(userIds)` (the Copilot bot has no stable userId to pass).
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

### Decisions
- **No Playwright in this repo** — it is code + HTTP API only; UI/Playwright lives in `laravel-ai-guardrails-admin`. (Per the project rule "se è solo codice non importa".)

### Control A — laravel/ai v0.8.1 JSON Schema model (VERIFIED 2026-06-16, diverges from the plan)
- The object passed to `Tool::schema(Illuminate\Contracts\JsonSchema\JsonSchema $schema)` at runtime is an **`Illuminate\JsonSchema\JsonSchemaTypeFactory`** (it implements the contract with INSTANCE methods `string()/integer()/object()/...`). The `Illuminate\JsonSchema\JsonSchema` class is NOT it — that one only has STATIC `__callStatic`. In the validator, instantiate `new JsonSchemaTypeFactory` to call `$tool->schema(...)`.
- **`Type::toArray()` on a LEAF type does NOT contain `required`.** `string()->required()->toArray()` === `['type' => 'string']`. The plan's `$definition['required']` assumption is WRONG for v0.8.1.
- **Read `required` from the parent object instead:** `$factory->object($schemaMap)->toArray()` yields `{ "properties": {key:{"type":...}}, "type":"object", "required":[<required key names>] }`. So: `properties` = key→leaf-def (has `type`), `required` = list of required key names. This is the public-API way; do NOT reflect on the protected `$required` prop.
- `Laravel\Ai\Tools\Request`: `__construct(protected array $arguments = [])`, `implements Arrayable, ArrayAccess`, `all(mixed $keys=null): array`, `toArray(): array`. Construct new args via `new Request([...])` (no setter).
- Tool contract import: `schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array`, `handle(\Laravel\Ai\Tools\Request): Stringable|string`, `description(): Stringable|string`.
- PHP 8.5 deprecation: `ReflectionProperty::setAccessible()` is deprecated/no-op — don't call it.

### To verify during implementation (do not invent)
- `padosoft/laravel-flow` config keys to enable persistence + lock store in Testbench, migration dir name, `FlowRun->id` property.
- Screener matched-span byte offsets (PCRE without `/u` = byte offsets; with `/u` careful with multibyte).
- Trend `GROUP BY` day dialect (sqlite/mysql `substr`+`blocked=1` vs Postgres `to_char`/`case when`).
