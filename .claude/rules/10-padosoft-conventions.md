# Rule 10 — Padosoft Package Conventions (binding)

Ported from the Padosoft package family (evidence-risk-review / eval-harness skeletons). Referenced rule ids used across the plan:

- **R9 — Docs match code.** Every config key, command, route name, and class quoted in README/docs MUST exist exactly in code. No drift.
- **R39 — Release/tagging.** Standalone packages: once all sub-tasks merged + CI green, tag plain SemVer (`v0.1.0`, not RC) at the closure SHA; cut a GitHub Release.
- **R43 — Both-states flag testing.** Every behaviour-changing toggle is tested in BOTH states (and three states for `enforce|monitor|off` modes).
- **R44 — Tri-surface discipline.** Every capability reachable from PHP + Artisan + (where it makes sense) an HTTP/MCP-friendly service. HTTP API mirrors the eval-harness `ReportApi` house style: `{schema_version, schema, data}` envelope, route closure `(Registrar, prefix, middleware)`, thin controllers, `JsonResource` shapers, names `<pkg>.api.*`, default-OFF behind `api.enabled`.
- **Immutability / append-only.** Audit/log stores never UPDATE or DELETE in place; the model throws on update/delete. GDPR erasure goes through a sanctioned, audited maintenance command (Rule 20).
- **Compose-not-couple.** Optional vendors (`laravel-flow`, `laravel-pii-redactor`) are `suggest`, guarded by `class_exists`, bound to null-object implementations at boot. Adapter code is confined to dedicated dirs (`src/Hitl`, `src/Output`); enforced by an architecture test.

## Skeleton mirroring
- Mirror `padosoft/laravel-evidence-risk-review` for package structure (composer.json, pint.json, phpunit.xml, phpstan.neon, CI matrix).
- HTTP API mirrors `padosoft-eval-harness` `ReportApi` (`ReportApiSchema`, `routes/*-api.php`, default-OFF gate in the provider).
- Config: every control is a toggle; default-OFF where behaviour-changing; env-driven defaults.
