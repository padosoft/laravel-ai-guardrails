# Rule 20 — Security Posture (binding)

This is a security product. The bar is higher than a normal package.

## Untrusted-input posture
- Model-chosen tool arguments are UNTRUSTED: re-scope owner keys server-side (recursive), validate against the tool schema, reject unknown args. Re-scoping is NOT authorization — gate tool use separately (Control A / Task E7).
- Prompts are UNTRUSTED: normalize (NFKC, strip zero-width/control, casefold) BEFORE pattern matching to defeat homoglyph/zero-width/case evasion; bound length (`max_prompt_length`); `/u`-flagged PCRE.
- Model OUTPUT is UNTRUSTED: escape HTML / neutralize markdown link+image+autolink+`javascript:`/`data:` URI exfil vectors; validate structured fields; compose PII redaction. Control C rewrites `$response->text` only — `toolCalls` are governed by A/D (documented limitation).

## Fail-safe defaults
- Regex safety: validate patterns at boot, bound `pcre.backtrack_limit`, and on `preg_match` error **fail closed** (treat as blocked) + log — never silently fail open.
- Enforce/monitor/off modes: `monitor` detects + audits + emits an event but does NOT block (shadow rollout).

## Data hygiene & privacy
- The append-only audit stores raw prompts → keep PII/secrets out: `audit_hygiene.prompt_storage = redact|hash|truncate|raw`. Default `redact` (compose pii-redactor).
- GDPR erasure vs append-only: resolved by the sanctioned, actor-audited `ai-guardrails:purge` maintenance command (the only place rows leave the table), with `retention.strategy = anonymize|purge|keep`.
- Every security-config mutation (`PUT /settings`) is itself append-only-audited with the actor.

## Observability
- Emit domain events (`InjectionBlocked`, `InjectionObserved`, `ToolArgumentRejected`, `DestructiveToolRouted`, `OutputSanitized`, `SettingsChanged`) from the same path that writes the audit row, so the host can wire SIEM/Slack/PagerDuty.
- Mutation testing (Infection, min MSI ≥ 80) proves the tests actually catch regressions.
