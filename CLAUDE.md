# CLAUDE.md — laravel-ai-guardrails

Project memory. Read `AGENTS.md` for the full working method, `.claude/rules/` for binding rules, `docs/LESSON.md` for accumulated know-how, `docs/PROGRESS.md` for where work stands.

## What this is

`padosoft/laravel-ai-guardrails` — deterministic, offline-first prompt-injection guardrails for `laravel/ai`. Four composable controls:
- **A — Tool Firewall:** re-scope model-chosen tool args to the authenticated principal + validate against the tool's own JSON schema (untrusted-input posture).
- **B — Input Screening + Injection Audit:** screen prompts (after normalization), refuse pre-model, append-only-log every attempt. The audit is the value.
- **C — Output Handler:** treat model output as untrusted — sanitize HTML/markdown, validate structured fields, compose `laravel-pii-redactor`.
- **D — HITL Bridge:** route destructive tool calls through `laravel-flow`'s `approvalGate()`.

## Hard rules

- Stack: PHP `^8.3`, Laravel 13, PHPUnit 12 + Testbench 11 (NOT Pest). Test on **PHP 8.5 via Herd** (`php85.bat`).
- Compose, don't couple: `laravel-flow` / `laravel-pii-redactor` are optional (suggest) with `class_exists` null-object graceful degradation. Adapter code lives only in `src/Hitl` (flow) and `src/Output` (pii); no other `src/` file may reference those vendors (enforced by the architecture test).
- Every behaviour-changing feature is a config toggle, default-OFF where it changes behaviour, and **tested in BOTH states** (and three states for enforce/monitor/off).
- Audit stores are **append-only** (model throws on update/delete).
- Docs must match code: every config key / command / class quoted in README must exist.
- TDD always: failing test → run (FAIL) → minimal impl → run (PASS) → both-states test → mutation gate (`infection --min-msi=80`) → commit.

## Surfaces (tri-surface discipline)

PHP facade + Artisan commands + HTTP API (default-OFF behind `api.enabled`, `{schema_version, schema, data}` envelope, names `ai-guardrails.api.*`). MCP is a documented follow-up.
