<?php

declare(strict_types=1);

namespace Padosoft\AiGuardrails\Output;

use HTMLPurifier;
use HTMLPurifier_Config;
use Padosoft\AiGuardrails\Contracts\OutputSanitizer;

/**
 * Chooses the HTML/markdown sanitizer at boot (Task L2). In `html_mode=allowlist`, prefers the
 * HTMLPurifier-backed sanitizer when `ezyang/htmlpurifier` is installed and gracefully falls back to
 * the built-in allowlist when it is not. `escape` mode always uses the built-in sanitizer. Keeps the
 * optional-vendor reference inside the src/Output boundary (compose-not-couple).
 */
final class HtmlSanitizerFactory
{
    /**
     * The small set of safe, non-link inline/flow tags kept by the allowlist (mirrors the built-in
     * allowlist's tag set). Links and images are absent, so there is no exfiltration target.
     */
    private const ALLOWED_TAGS = 'b,i,em,strong,code,br,p,ul,ol,li';

    /**
     * HTMLPurifier permits a set of benign global attributes (class, id, lang, dir, title,
     * xml:lang, style) on whitelisted elements by default. We explicitly forbid them so the output
     * carries no attributes at all, matching the built-in allowlist's strip-all-attrs behaviour.
     * HTMLPurifier's ForbiddenAttributes uses plain `attr` (not `*.attr`) for global scope.
     */
    private const FORBIDDEN_ATTRS = 'class,id,lang,dir,title,xml:lang,style';

    /** @param  bool|null  $purifierAvailable  override the class_exists probe (testing seam). */
    public static function make(
        string $htmlMode,
        bool $sanitizeHtml,
        bool $neutralizeMarkdown,
        ?bool $purifierAvailable = null,
    ): OutputSanitizer {
        $available = $purifierAvailable ?? class_exists(HTMLPurifier::class);

        if ($sanitizeHtml && $htmlMode === 'allowlist' && $available) {
            return new PurifierHtmlSanitizer(
                self::buildPurifier(),
                // Markdown-only delegate: HTML is handled by HTMLPurifier, so disable the HTML pass.
                new HtmlMarkdownSanitizer(sanitizeHtml: false, neutralizeMarkdown: $neutralizeMarkdown, htmlMode: 'escape'),
            );
        }

        return new HtmlMarkdownSanitizer($sanitizeHtml, $neutralizeMarkdown, $htmlMode);
    }

    private static function buildPurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED_TAGS);
        $config->set('HTML.ForbiddenAttributes', self::FORBIDDEN_ATTRS);
        $config->set('Core.Encoding', 'UTF-8');
        // Disable the definition cache so no writable cache directory is required (portable in CI).
        $config->set('Cache.DefinitionImpl', null);

        return new HTMLPurifier($config);
    }
}
