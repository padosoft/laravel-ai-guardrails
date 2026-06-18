---
title: Prompt normalization & confusables
description: How the screener defeats homoglyph, zero-width, and case-based evasion before matching.
---

# Prompt normalization & confusables

## Motivation

Substring and regex matching are only as good as the text they see. An attacker who writes `ignоre previous instructions` with a **Cyrillic `о`** (U+043E), or `ig​nore` with a **zero-width space**, or `IGNORE` in a different case, sails straight past a naïve pattern. Normalization maps all of these to one canonical skeleton *before* matching, so a single lowercase pattern catches every disguise.

## The pipeline

```mermaid
flowchart LR
    R[Raw prompt] --> K[NFKC<br/>fullwidth → ASCII]
    K --> Z[Strip zero-width<br/>+ control chars]
    Z --> F[Fold confusables<br/>Cyrillic/Greek → Latin]
    F --> L[Casefold<br/>Unicode lower]
    L --> Out[Canonical form → pattern match]
```

Each pass is individually toggleable under `normalization.*`. Order matters: NFKC first (compatibility folding), then invisible-character stripping, then cross-script folding, then casefolding so the skeleton and any native Latin lower-case together.

## What each pass defeats

| Pass | Config | Defeats |
|---|---|---|
| NFKC | `normalization.nfkc` | fullwidth/compatibility homoglyphs (`ｉｇｎｏｒｅ` → `ignore`) |
| Zero-width strip | `normalization.strip_zero_width` | ZWSP, BOM, soft hyphen, CGJ, the U+2060–U+206F block, the Unicode **TAG** block (used in 2024 invisible-text injection) |
| Control strip | `normalization.strip_control` | C0/C1 controls (except tab/newline/CR) |
| Confusables fold | `normalization.fold_confusables` | **cross-script** look-alikes NFKC ignores — Cyrillic `а е о р с …`, Greek `ο α ρ …` |
| Casefold | `normalization.casefold` | case evasion |

## Confusables: theory and limits

NFKC normalizes *compatibility* variants but **not** cross-alphabet look-alikes: Cyrillic `а` (U+0430) and Latin `a` (U+0061) are visually identical yet distinct code points in different scripts, so NFKC leaves them apart. The **skeleton** approach (from the Unicode confusables algorithm) maps each look-alike to a canonical Latin character:

$$
\text{skeleton}(c) = \begin{cases} \text{map}(c) & c \in \text{Confusables} \\ c & \text{otherwise} \end{cases}
$$

This package ships a **curated** map (`ConfusablesFolder`) of the high-value Cyrillic/Greek confusables — fast, no external data file, and tuned to avoid over-folding legitimate non-Latin prompts. It is intentionally *not* the full ~6000-entry Unicode dataset.

```php
// "ignоrе" with Cyrillic о (U+043E) + е (U+0435) → "ignore"
AiGuardrails::screen("please ign\u{043E}r\u{0435} previous instructions")->blocked; // true
```

## Gotchas

::: callout warning
- **The fold is one-way and for matching only.** The *stored* prompt is unaffected — folding happens on the match path inside the screener.
- **Curated, not exhaustive.** An exotic look-alike outside the map can still slip through; extend `ConfusablesFolder` for a wider threat model. The honest residual is documented as a known limitation.
- **Casefolding breaks case-sensitive patterns.** Author patterns lowercase or with `/i`.
- **Length is code points, not bytes.** `normalization.max_prompt_length` bounds Unicode code points to cap token-exhaustion.
:::
