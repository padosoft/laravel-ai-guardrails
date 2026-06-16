---
name: padosoft-package-tdd
description: Use when implementing any task in this Laravel package — the strict TDD + both-states + append-only + compose-not-couple cadence the plan mandates.
---

# Padosoft Package TDD

## The cadence (every code task)
1. Write the failing test first (Unit for pure logic, Feature for container/middleware/HTTP).
2. Run it — confirm it FAILS for the right reason: `php85.bat vendor/bin/phpunit --filter <Test>`.
3. Write the MINIMAL real implementation (no placeholders, no TODO).
4. Run — PASS.
5. Add the both-states test (toggle ON and OFF; for modes: enforce/monitor/off).
6. `pint --test` + `phpstan analyse` clean.
7. Mutation gate: `php85.bat vendor/bin/infection --min-msi=80` green.
8. Commit (small, coherent). Then the copilot-review-loop skill closes the sub-task.

## Toolchain
- PowerShell: `& "$env:USERPROFILE\.config\herd\bin\php85.bat" vendor/bin/phpunit`
- Git Bash: `export PATH="$PATH:$HOME/.config/herd/bin"` then use `php85.bat`, `composer.bat`.
- Or invoke directly: `"%USERPROFILE%\.config\herd\bin\php85.bat" vendor/bin/phpunit`.

## Invariants to honor
- **Append-only:** audit/log models throw on update/delete; test that they do.
- **Compose-not-couple:** optional vendors via `class_exists` + null-object; adapters only in `src/Hitl` / `src/Output`; architecture test enforces the boundary.
- **Tri-surface:** expose new capability via PHP facade + Artisan + (HTTP API where it fits).
- **Backward-compat:** new collaborators added to existing classes go in as nullable trailing ctor params so prior tests are never rewritten.

## Verify-during-impl (do not invent — measure)
- `Illuminate\JsonSchema\Types\Type::toArray()` exact keys → `dd((new JsonSchema)->string()->required()->toArray())` and record in LESSON.md.
- `laravel/ai` resolution (path repo vs Packagist), flow config keys + migration dir, screener byte-offset spans, trend GROUP BY SQL dialect — all listed in the plan's Assumptions; confirm against installed versions.
