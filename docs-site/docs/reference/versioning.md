---
title: Versioning & releases
description: SemVer policy and the release history.
---

# Versioning & releases

The package follows **Semantic Versioning**. The contract you depend on:

- **Public API** = the `AiGuardrails` facade, the Artisan command signatures, the config keys, the dispatched events, and the `ai-guardrails.api.v1` HTTP envelope + route names.
- **Patch** — fixes, no API change.
- **Minor** — additive, backward-compatible (new toggles default-safe, new endpoints, new optional integrations).
- **Major** — a breaking change to the public API.

Every behaviour-changing feature is a config toggle, default-safe, and tested in both states — so minor upgrades never change behaviour unless you flip a flag.

## Release history

| Version | Theme |
|---|---|
| **v1.0.0** | Every documented limitation closed: cross-script confusables fold, HTMLPurifier-grade allowlist, opt-in tool-call sanitization, turnkey HITL commands, and the MCP surface. |
| **v0.3.0** | Enterprise hardening: enforce/monitor/off modes, domain events, audit hygiene + GDPR retention, settings-change audit, tool authorization, the mutation-testing gate, and overview API deltas. |
| **v0.2.0** | The admin HTTP API surface. |
| **v0.1.0** | The four controls + core scaffolding. |

See the [GitHub releases](https://github.com/padosoft/laravel-ai-guardrails/releases) for the full changelog of each tag.

## Upgrade posture

- **Read the release notes** — they call out any new default-safe toggle you may want to enable.
- **Adopt new controls in `monitor` first** (see [modes](/concepts/modes)) before enforcing.
- **Optional dependencies are additive** — installing `laravel-flow`, `laravel-pii-redactor`, `ezyang/htmlpurifier`, or `laravel/mcp` lights up the matching feature; removing one degrades gracefully to a null object.

::: callout info
The quality bar is enforced in CI on every release: tests on PHP 8.3 / 8.4 / 8.5 × Laravel 13, PHPStan level 8, Pint, and Infection ≥ 80% MSI. See [observability & mutation testing](/operations/observability).
:::
