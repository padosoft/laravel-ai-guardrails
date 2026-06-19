---
title: Domain events
description: Payload shapes for every dispatched event.
---

# Domain events

All events live under `Padosoft\AiGuardrails\Events` and are dispatched from the same code path that writes the audit/stat record, gated by `events.enabled` (default on).

| Event | Payload | `$enforced` |
|---|---|---|
| `InjectionBlocked` | `InjectionAttempt $attempt` | n/a — distinct class |
| `InjectionObserved` | `InjectionAttempt $attempt` | n/a — distinct class |
| `ToolArgumentRejected` | `FirewallRejection $rejection`, `bool $enforced` | `true` blocked / `false` monitor |
| `DestructiveToolRouted` | `string $toolName`, `int\|string\|null $principalId`, `string $runId`, `DateTimeImmutable $occurredAt` | n/a — enforce only |
| `OutputSanitized` | `string[] $kinds`, `DateTimeImmutable $occurredAt`, `bool $enforced` | `true` rewritten / `false` monitor |
| `SettingsChanged` | `?string $actorId`, `SettingsChange[] $changes`, `DateTimeImmutable $occurredAt` | n/a |

## DTO shapes

`InjectionAttempt` carries `prompt`, `blocked`, `ruleId`, `principalId`, `rulesetVersion`, `erroredRuleIds`, `matchedSpan`, `occurredAt`.

`FirewallRejection` carries `toolDescription`, `principalId`, `violations` (property ⇒ reason), `occurredAt`.

`SettingsChange` carries `actorId`, `key`, `oldValue`, `newValue`, `occurredAt`.

## Listening

```php
use Illuminate\Support\Facades\Event;
use Padosoft\AiGuardrails\Events\OutputSanitized;

Event::listen(OutputSanitized::class, function (OutputSanitized $e) {
    if (! $e->enforced) return; // skip shadow-mode observations
    metrics()->increment('guardrails.output_sanitized', tags: $e->kinds);
});
```

::: callout warning
`InjectionBlocked` / `InjectionObserved` carry the **raw prompt** via `$attempt->prompt`. Audit hygiene applies to the persisted row, not the in-process event — forward only the fields you need to external sinks.
:::

See the [events guide](/guides/events) for wiring patterns and the monitor-mode semantics.
