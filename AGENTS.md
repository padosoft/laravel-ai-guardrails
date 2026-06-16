# laravel-ai-guardrails — Agent Guide

This repository is the standalone Laravel package `padosoft/laravel-ai-guardrails`: deterministic, offline-first prompt-injection guardrails for `laravel/ai`. It ships four composable controls — **(A) Tool Firewall**, **(B) Input Screening + append-only Injection Audit**, **(C) Output Sanitization + structured-output validation + PII compose**, **(D) HITL Approval Bridge** over `padosoft/laravel-flow`.

The companion admin SPA lives in the sibling repo `../laravel-ai-guardrails-admin`.

## Durable context (read these first if context is missing)

- Implementation plan: `%USERPROFILE%\.claude\plans\2026-06-16-laravel-ai-guardrails.md` (Tasks -1 → E10).
- Admin plan: `%USERPROFILE%\.claude\plans\2026-06-16-laravel-ai-guardrails-admin.md`.
- `docs/PROGRESS.md` — where work currently stands (resume point after any interruption).
- `docs/LESSON.md` — everything learned / every bug+fix / every Copilot fix. **Pass this into every sub-agent and every new session.**
- `.claude/rules/` — the binding rules (working method, conventions, security posture).
- `.claude/skills/` — reusable procedures (TDD loop, Copilot review loop).

## Stack

- PHP `^8.3` (test locally on **PHP 8.5 via Herd**: `%USERPROFILE%\.config\herd\bin\php85.bat`; composer `composer.bat`).
- Laravel 13 (`illuminate/* ^13.0`), PHPUnit `^12.5` + Orchestra Testbench `^11.0` (NOT Pest), Pint `^1.18`, PHPStan `^2.0`, Infection `^0.29`.
- Suggested/optional (compose, do not couple): `padosoft/laravel-flow` (HITL), `padosoft/laravel-pii-redactor` (PII). Peer: `laravel/ai`.
- Local dependency clones: `../AskMyDocs/vendor/laravel/ai`, `../padosoft-laravel-flow`, `../padosoft-laravel-pii-redactor` (wire via composer path repositories during dev; record resolution in LESSON.md).

## Branching model (one branch per MACRO task)

Macro tasks: `Task -1` governance · `Task 0+1` scaffold+core · **Control A** (T2) · **Control B** (T3+E1+E2) · **Control C** (T4+E8) · **Control D** (T5) · **Surfaces** (T6+T9–18) · **Enterprise Hardening cross-cuts** (E3–E7, E9, E9-API) · **Release** (E10).

For each macro task: branch off `main` (e.g. `feature/control-a-tool-firewall`). Each sub-task opens its OWN PR **into the macro branch**. When the macro task is fully green, open the macro PR `→ main` and run the full DoD before merge.

## Definition of Done (DoD) — run this loop on EVERY sub-task/PR

1. All local tests green: `php85.bat vendor/bin/phpunit` (Unit/Feature/Architecture). Then mutation coverage: `php85.bat vendor/bin/infection --min-msi=80`.
2. Local Copilot review: generate the full branch diff and pass it to Copilot. If too large, write to a temp file first:
   ```powershell
   # PowerShell
   git diff origin/main...HEAD | Out-File "$env:TEMP\branch.diff" -Encoding utf8
   copilot --autopilot --yolo -p "/review the changes in $env:TEMP\branch.diff for the laravel-ai-guardrails package. Focus on security posture, untrusted-input handling, append-only invariants, and test coverage."
   ```
   Resolve **every** comment.
3. Only when local tests pass AND local Copilot review has **zero** comments → `git push`.
4. Open the PR toward the branch you are working on; set **Copilot as reviewer** and confirm the review actually started (see GraphQL note below).
5. Wait for BOTH: all CI checks green AND Copilot's PR comments.
6. If all green → merge. Otherwise fix broken tests + Copilot comments, update `docs/LESSON.md`, and **restart the loop**.
7. Only when everything is green → task done, advance.

### Requesting the GitHub Copilot reviewer (verified gotcha)

`gh pr edit <PR> --add-reviewer @copilot` can fail (it queries PR project items, needs `read:project`) and the REST `reviewers[]=copilot` endpoint can 200 WITHOUT creating a visible review. Use the GraphQL mutation instead:

```bash
# resolve node id
gh pr view <PR> --json id
# request the Copilot review bot
gh api graphql -f query='mutation($pid:ID!,$bots:[String!],$u:Boolean!){requestReviewsByLogin(input:{pullRequestId:$pid,botLogins:$bots,union:$u}){clientMutationId}}' \
  -F pid='<PR_NODE_ID>' -F bots[]='copilot-pull-request-reviewer[bot]' -F u=true
gh api repos/padosoft/laravel-ai-guardrails/pulls/<PR>/requested_reviewers
```

Do NOT use `@codex review` as a substitute unless the user explicitly asks. Do not stop after a push/review request — keep polling PR status, CI, and review comments until resolved. If GitHub/Copilot access is unavailable, do NOT fake the loop: record the local status + next remote step in `docs/PROGRESS.md`.

## Guardrails for "task done" (project rule)

Every task/sub-task must have: a precise objective, implementation detail, and **precise guardrails with tests** — PHPUnit (and Vite where relevant). **UI/UX work also requires Playwright scenarios for every interaction.** This package is **code + HTTP API only (no UI)** → **no Playwright here**; UI/Playwright belongs to `laravel-ai-guardrails-admin`. Record this decision in LESSON.md so it is not re-litigated.

## Continuity

Update `docs/PROGRESS.md` after meaningful steps; update `docs/LESSON.md` whenever you learn something non-obvious or receive a Copilot fix. Keep entries dated `YYYY-MM-DD`. At the start of every new session and every parallel sub-agent dispatch, pass `docs/LESSON.md` into context.

## Final task (E10)

Re-read LESSON.md and consolidate the new know-how back into `AGENTS.md`, `.claude/rules/`, `.claude/skills/`, `CLAUDE.md`; then tag + GitHub release per the release rule.
