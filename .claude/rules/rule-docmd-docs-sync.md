# Rule — Documentation auto-sync (binding)

The public documentation site lives in `docs-site/` (docmd → Cloudflare Pages at
`https://doc.laravel-ai-guardrails.padosoft.com`). It is part of the product, not an afterthought.

## You MUST

- **Whenever you add or change a user-facing feature** — a new config key, Artisan command, facade
  method, control behaviour, event, HTTP/MCP endpoint, or any change a *user* would observe — you
  **MUST**, in the **same unit of work**, update the corresponding page under `docs-site/docs/**`.
- **Whenever you make a substantial change to the package README** (new section, changed semantics,
  new limitation closed), you **MUST** mirror it in the matching docmd page(s).
- If the page is **new**, you **MUST** register it in `navigation[]` in `docs-site/docmd.config.json`
  (a page not listed there does not appear in the sidebar).
- Follow the **`docmd-docs` skill**: Markdown-only (never MDX/JSX), the container syntax (`:::`),
  Lucide icons, the page-structure standard (motivation → theory → Mermaid → data model → ADR →
  worked example → gotchas), and the semantic-search constraints.
- **Before closing the work**, run `cd docs-site && npm run check && npm run build` and confirm both
  are **green** (guard passes, `_site/index.html` present, 0 visible `:::`).

## When you do NOT need to touch the docs

Skip the doc update — but **say so explicitly** in the changelog/PR description (e.g.
"docs: n/a — internal refactor") — when the change is:

- a purely internal refactor with no observable behaviour change;
- a tooling/CI/test-only change;
- a cosmetic change (formatting, comments, typos in code);
- a dependency bump with no API impact.

If you are unsure whether a change is user-facing, treat it as user-facing and update the docs.

## Anti-patterns (do NOT)

- Ship a user-facing feature without touching `docs-site/docs/**`.
- Add a page but forget to list it in `navigation[]`.
- Reintroduce MDX/JSX or raw component tags into `.md` files (the `npm run check` guard rejects them;
  note that `list<Capitalized>` generics also trip it — write `Capitalized[]`).
- Let the README and the docs drift apart on the same fact.
- Close the task with a red `npm run build`.
