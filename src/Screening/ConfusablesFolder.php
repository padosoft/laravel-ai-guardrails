<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Screening;

/**
 * One-way "skeleton" fold of common cross-script homoglyphs to their Latin look-alike (Task L1).
 *
 * NFKC collapses fullwidth/compatibility characters but NOT cross-alphabet look-alikes: the Cyrillic
 * `о` (U+043E) is visually identical to the Latin `o` yet is a different code point, so `ignоre`
 * sails past the screening regexes. This folder maps a CURATED, high-value set of Cyrillic / Greek /
 * common-symbol confusables to a lowercase Latin skeleton so the screener matches the intended word.
 *
 * Scope is deliberately a curated subset (not the full Unicode confusables data) to keep the fold
 * fast and to avoid over-folding legitimate non-Latin prompts — only characters that genuinely
 * impersonate a Latin letter are mapped. The fold is applied ONLY on the screener's match path; the
 * stored / audited prompt is never rewritten. It is lossy and one-way (a skeleton, not a reversible
 * normalisation).
 */
final class ConfusablesFolder
{
    /**
     * Confusable code point => Latin skeleton (lowercase). Upper- and lower-case homoglyphs both map
     * to the lowercase skeleton because folding runs before casefolding in the normalizer.
     *
     * @var array<string,string>
     */
    private const MAP = [
        // ── Cyrillic lowercase ──
        "\u{0430}" => 'a', "\u{0435}" => 'e', "\u{043E}" => 'o', "\u{0440}" => 'p',
        "\u{0441}" => 'c', "\u{0445}" => 'x', "\u{0443}" => 'y', "\u{0456}" => 'i',
        "\u{0458}" => 'j', "\u{0455}" => 's', "\u{051B}" => 'q', "\u{051D}" => 'w',
        "\u{04BB}" => 'h', "\u{0501}" => 'd',
        // Lowercase counterparts of uppercase-mapped Cyrillic — mb_strtolower folds Cyrillic case AFTER
        // the confusables pass, so without these an attacker using native lowercase Cyrillic evades:
        "\u{043C}" => 'm', // м small em  (≈ Latin m)
        "\u{043D}" => 'h', // н small en  (≈ Latin h in many fonts)
        "\u{0432}" => 'b', // в small ve  (≈ Latin b in bold fonts)
        "\u{0442}" => 't', // т small te  (≈ Latin t in italic/monospace)
        "\u{043A}" => 'k', // к small ka  (≈ Latin k)
        // ── IPA / Phonetic Latin ──
        "\u{0261}" => 'g', // Latin small letter script g (IPA) ≈ Latin g
        // ── Cyrillic uppercase (→ lowercase Latin skeleton) ──
        "\u{0410}" => 'a', "\u{0412}" => 'b', "\u{0415}" => 'e', "\u{041A}" => 'k',
        "\u{041C}" => 'm', "\u{041D}" => 'h', "\u{041E}" => 'o', "\u{0420}" => 'p',
        "\u{0421}" => 'c', "\u{0422}" => 't', "\u{0425}" => 'x', "\u{0423}" => 'y',
        "\u{0406}" => 'i', "\u{0408}" => 'j',
        // ── Greek lowercase ──
        "\u{03BF}" => 'o', "\u{03B1}" => 'a', "\u{03C1}" => 'p', "\u{03BD}" => 'v',
        "\u{03B9}" => 'i', "\u{03BA}" => 'k', "\u{03C5}" => 'u',
        "\u{03C4}" => 't', // τ small tau (≈ Latin t; uppercase Τ already mapped above)
        // ── Greek uppercase (→ lowercase Latin skeleton) ──
        "\u{0391}" => 'a', "\u{0392}" => 'b', "\u{0395}" => 'e', "\u{0397}" => 'h',
        "\u{0399}" => 'i', "\u{039A}" => 'k', "\u{039C}" => 'm', "\u{039D}" => 'n',
        "\u{039F}" => 'o', "\u{03A1}" => 'p', "\u{03A4}" => 't', "\u{03A7}" => 'x',
        "\u{03A5}" => 'y', "\u{0396}" => 'z',
        // ── Common symbol / fullwidth-miss confusables ──
        "\u{2044}" => '/', // fraction slash
        "\u{2215}" => '/', // division slash
    ];

    public function fold(string $text): string
    {
        return strtr($text, self::MAP);
    }
}
