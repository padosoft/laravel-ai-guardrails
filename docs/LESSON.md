# Lessons

> Pass this file into every new session and every sub-agent. Date entries `YYYY-MM-DD`.

## 2026-06-16

### Environment (this machine)
- PHP 8.5.7 + Composer 2.9.7 are available via **Herd**, NOT in the bash PATH. Use `%USERPROFILE%\.config\herd\bin\php85.bat` and `composer.bat` (or `export PATH="$PATH:/c/Users/lopad/.config/herd/bin"` in Git Bash).
- GitHub: remote `git@github.com:padosoft/laravel-ai-guardrails.git`, SSH, `gh` authenticated as `lopadova`. `copilot` CLI v1.0.63 present.
- `resources/` already contains `laravel-ai-guardrails-Banner.png` (banner) and `laravel-ai-guardrails-admin-Dashboard-Dark.png` (screenshot) → use them in the README (note the actual filenames; the plan said `banner.png` generically).

### Local dependency clones (for composer path repositories during dev)
- `laravel/ai` → `../AskMyDocs/vendor/laravel/ai` (verified `src/` present).
- `padosoft/laravel-flow` → `../padosoft-laravel-flow`.
- `padosoft/laravel-pii-redactor` → `../padosoft-laravel-pii-redactor`.
- TODO (Task 0): decide path-repository vs Packagist resolution for `laravel/ai` and record the final composer.json `repositories` block here.

### Governance (ported from product_image_discovery_admin)
- The reference repo keeps governance in `AGENTS.md` + `docs/RULES.md` (+ `.agents/`), not `.claude/`. We use Claude format: `AGENTS.md`, `CLAUDE.md`, `.claude/rules/`, `.claude/skills/`.
- **Copilot reviewer gotcha (important):** request via GraphQL `requestReviewsByLogin` with bot login `copilot-pull-request-reviewer[bot]`. `gh pr edit --add-reviewer @copilot` can fail (needs `read:project`) and REST `reviewers[]=copilot` can 200 without creating a visible review. See `.claude/skills/copilot-review-loop`.
- Do not use `@codex review` as a Copilot substitute unless explicitly asked.

### Governance doc fixes (2026-06-16 review)
- `php85` (bare) won't resolve on Windows; always use `php85.bat` or the full `%USERPROFILE%\.config\herd\bin\php85.bat` path.
- `copilot-review-loop/SKILL.md` was bash-only. Rewrote step 1 as PowerShell-first with a Git Bash alternative. `/tmp/branch.diff` → `$env:TEMP\branch.diff`.
- Hardcoded username `lopad` in `padosoft-package-tdd/SKILL.md` toolchain path replaced with `$env:USERPROFILE` / `$HOME`.
- Infection/MSI gate (`vendor/bin/infection --min-msi=80`) was absent from every DoD checklist despite Rule 20 mandating it. Added to: `AGENTS.md` step 1, `CLAUDE.md` TDD cycle, `padosoft-package-tdd/SKILL.md` step 7, `00-working-method.md` DoD summary.
- AGENTS.md DoD step 1 mentioned "JS/Vite assets" — removed (pure PHP/API package, no JS/Vite).

### Decisions
- **No Playwright in this repo** — it is code + HTTP API only; UI/Playwright lives in `laravel-ai-guardrails-admin`. (Per the project rule "se è solo codice non importa".)

### To verify during implementation (do not invent)
- `Illuminate\JsonSchema\Types\Type::toArray()` exact key names (`type`, `required`?) — measure with `dd()`.
- `padosoft/laravel-flow` config keys to enable persistence + lock store in Testbench, migration dir name, `FlowRun->id` property.
- Screener matched-span byte offsets (PCRE without `/u` = byte offsets; with `/u` careful with multibyte).
- Trend `GROUP BY` day dialect (sqlite/mysql `substr`+`blocked=1` vs Postgres `to_char`/`case when`).
