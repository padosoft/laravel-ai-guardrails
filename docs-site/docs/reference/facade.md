---
title: PHP facade
description: The AiGuardrails facade — the single PHP entry point.
---

# PHP facade

Everything is reachable from `Padosoft\AiGuardrails\Facades\AiGuardrails`.

```php
use Padosoft\AiGuardrails\Facades\AiGuardrails;

AiGuardrails::screen(string $prompt): ScreenVerdict;                                  // Control B
AiGuardrails::sanitize(string $text): string;                                        // Control C
AiGuardrails::guard(Tool $tool, ?Closure $principalResolver = null): Tool;           // Control A
AiGuardrails::routeForApproval(Tool $tool, string $toolName, ?Closure $principalResolver = null): Tool; // Control D
AiGuardrails::isDestructive(string $toolName): bool;
AiGuardrails::validateStructured(array $output, array $schema, bool $rejectUnknown = false): array; // Control C
```

## `screen()`

Returns a `ScreenVerdict` with `->blocked`, `->ruleId`, `->refusalMessage`, `->rulesetVersion`, `->matchedSpan`. Does **not** audit on its own — the audit happens inside `GuardrailInputMiddleware`. Use it for ad-hoc checks.

```php
$v = AiGuardrails::screen($prompt);
if ($v->blocked) abort(422, $v->refusalMessage);
```

## `sanitize()`

Runs the full Control C pipeline (HTML/markdown sanitize → PII redact) over a string and returns the cleaned text.

## `guard()`

Wraps a `laravel/ai` Tool with the firewall (and the authorization gate when `tool_authorization.enabled`). No-op when the master switch or the firewall mode is off. The optional `$principalResolver` overrides how the principal is resolved (default `auth()->guard()->id()`).

## `routeForApproval()`

Wraps a destructive tool with the HITL bridge. No-op when HITL is off. The fallback (`deny` / `pass`) applies when approval is unavailable.

## `validateStructured()`

Validates a decoded structured array against a `laravel/ai` schema map and returns `field => violation` (empty = valid). Records a `structured_validation_failure` stat when violations are found.

::: callout tip
When the master kill-switch is off, `guard()` / `routeForApproval()` return the tool untouched, `screen()` allows, and `sanitize()` passes through — so you can wrap everything unconditionally and toggle protection from config.
:::
