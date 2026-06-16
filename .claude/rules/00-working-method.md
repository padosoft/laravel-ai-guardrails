# Rule 00 — Working Method (binding)

## Branch & PR loop
- One branch per MACRO task (off `main`). Each sub-task → its own PR into the macro branch. Macro task green → macro PR into `main`.
- Definition of Done loop (per sub-task/PR): local tests green → local `copilot --autopilot --yolo /review <full branch diff vs origin/main>` with ZERO comments → push → open PR → request **Copilot** reviewer → wait CI green + Copilot comments → fix loop → merge. Only then advance.
- Request the Copilot reviewer via the GraphQL `requestReviewsByLogin` mutation (bot `copilot-pull-request-reviewer[bot]`); `gh pr edit --add-reviewer @copilot` and the REST endpoint are unreliable. See `AGENTS.md`.
- Never fake the loop. If GitHub/Copilot is unavailable, record the local status + next remote step in `docs/PROGRESS.md`.

## "Task done" guardrails
- Every task/sub-task: precise objective + implementation detail + precise test guardrails (PHPUnit; Vite where relevant).
- UI/UX work additionally requires Playwright scenarios for every interaction. **This package has no UI → no Playwright here** (it belongs to `laravel-ai-guardrails-admin`).

## Continuity
- Update `docs/PROGRESS.md` after meaningful steps; `docs/LESSON.md` on every non-obvious discovery or Copilot fix. Date entries `YYYY-MM-DD`.
- Pass `docs/LESSON.md` into every new session and every sub-agent.

## Toolchain (this machine)
- PHP 8.5 via Herd: `%USERPROFILE%\.config\herd\bin\php85.bat`; composer `composer.bat`. Do not rely on bare `php`/`composer` in PATH.
