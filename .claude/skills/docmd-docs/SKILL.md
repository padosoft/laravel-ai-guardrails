---
name: docmd-docs
description: Use when working inside docs-site/ — authoring or editing documentation pages, adding/reordering sidebar navigation, touching docmd plugins (search/git/seo/sitemap/mermaid/math/llms), or keeping the docs in sync with package features. Covers docmd syntax, semantic search, build/verify, and the Cloudflare Pages deploy.
---

# docmd documentation site (`docs-site/`)

The public docs are a [docmd](https://docs.docmd.io) static site in `docs-site/`, deployed to Cloudflare Pages at `https://doc.laravel-ai-guardrails.padosoft.com`. **Markdown only — never MDX/JSX.** A CI guard (`npm run check`) rejects raw component tags.

## Layout

```
docs-site/
  docmd.config.json          # metadata, url, navigation[], theme, plugins
  package.json               # scripts: dev / build / check
  .node-version              # "20" (Cloudflare auto-pin)
  .docmd-search/config.json  # PINNED embedding model (committed — skips the wizard)
  assets/{favicon.svg,custom.css}   # brand: --docmd-color-primary #0d9488
  scripts/check-no-raw-html.mjs     # guard: fails on JSX/component tags in docs/**.md
  docs/                       # every page; routes mirror the tree (docs/foo/bar.md → /foo/bar, docs/index.md → /)
  _site/                      # build output (git-ignored)
```

Route rule: `docs/guides/foo.md` → `/guides/foo`. `docs/index.md` → `/` (the homepage is **mandatory**).

## Commands

```bash
cd docs-site
npm install
npm run check   # guard: no raw component tags
npm run build   # → _site/
npm run dev     # local preview
```

## Navigation is the single source of the sidebar

`navigation[]` in `docmd.config.json` is the **only** sidebar source — a page not listed there does not appear. Add every new page to it. Icons are **Lucide** names in kebab-case (https://lucide.dev), e.g. `shield`, `book-open`, `wrench`, `settings`, `list`, `rocket`.

## Container syntax (no MDX/JSX)

| Need | Syntax |
|---|---|
| Callout | `::: callout info` … `:::` (types: `info`, `tip`, `warning`, `danger`, `success`) |
| Tabs | `::: tabs` then `== tab "Label"` blocks, close `:::` |
| Steps | `::: steps` then a numbered list `1. **Title**` with body indented **3 spaces**, close `:::` |
| Collapsible | `::: collapsible "Title"` … `:::` (prefix `open` to expand by default; no exclusive accordion) |
| Cards | `::: grids` › `::: grid` › `::: card "Title" icon:lucide-name` › body › `[Open →](/path)` › close each `:::` |
| Diagram | fenced ` ```mermaid ` (flowchart, sequenceDiagram, stateDiagram-v2, …) |
| Math | KaTeX `$…$` inline / `$$…$$` block |

## Plugins (all on)

- **search** `{ semantic: true }` → `docmd-search`: embeddings computed at **build time** with ONNX Runtime; the browser gets quantized Int8 vectors + keyword match, 100% client-side. The model is pinned in `.docmd-search/config.json` (`Xenova/all-MiniLM-L6-v2`) to **skip the interactive wizard** that blocks CI. `.gitignore` keeps that one file (`!.docmd-search/config.json`) and ignores the rest of `.docmd-search/`.
- **git** → needs `repo` + a CI checkout with full history (`fetch-depth: 0`) for edit links / last-updated.
- **seo / sitemap / llms** → require the root `url` field. `llms` emits `llms.txt` + `llms-full.txt`.
- **mermaid / math** → no setup beyond the fence / `$…$`.

Dev deps: `@docmd/core`, `docmd-search`, `@huggingface/transformers`, `onnxruntime-node`.

## Footer / branding

`footer.content` credits Padosoft + GitHub + license; `footer.branding: true` keeps the docmd credit. Brand color is set in `assets/custom.css` via `--docmd-color-primary` / `--color-primary` / `--link-color`.

## Page structure standard (deep pages)

1. **Motivation** — the problem, why it exists.
2. **Theory** — formal definitions, KaTeX where it pays (metrics, complexity, posture).
3. **Design + Mermaid diagram** — architecture / pipeline / flow.
4. **Data model / contract** — schema, tables, examples.
5. **ADR** — `Problem → Decision → Consequences`, one `::: collapsible` per record.
6. **Worked example** — concrete end-to-end code.
7. **Gotchas** — real pitfalls in `::: callout warning`.

Keep a page per: Architecture overview, Pipeline, Decision records (ADR), Reference, plus Quickstart and practical guides. Mermaid is plain text → readable by AI agents and indexed by search.

## Deploy — Cloudflare Pages (Option A, primary, no API key)

Use the CF Pages **Git integration** (builds on every push to `main`):

| Field | Value |
|---|---|
| Production branch | `main` |
| Root directory | `docs-site` |
| Build command | `npm run build` |
| Build output | `_site` |
| Node | auto via `.node-version` (20) |

The ONNX search build runs on CF's Linux image. Commit a **lockfileVersion 3** `package-lock.json` so `npm ci` resolves the Linux natives (`@img/sharp-linux-x64`, onnxruntime linux) even though the lock was generated on another OS. Map `doc.laravel-ai-guardrails.padosoft.com` under Custom domains.

**Option B (fallback, not auto-run):** a `workflow_dispatch`-only GitHub Actions job that builds and runs `wrangler pages deploy _site --project-name=laravel-ai-guardrails` (secrets `CLOUDFLARE_API_TOKEN` + `CLOUDFLARE_ACCOUNT_ID`). Use only if CF's build breaks on ONNX.

## Gotchas (verify with a real build)

1. **`docs/index.md` is mandatory** (route `/`), else "Root index.html not found".
2. **`::: button` is not a paired block** — its closing `:::` leaks as text; inside cards use a markdown link `[Open →](/path)`.
3. **Steps**: body indented to **3 spaces** so nested fences/callouts stay inside the list item.
4. **Math**: KaTeX only processes `$…$` outside code blocks; check rendered HTML for stray `class="katex"` on pages full of `$variable` code.
5. **Generics trip the guard**: `list<UpperCase>` matches the JSX-tag regex — write `UpperCase[]` or avoid `<Capitalized>` in prose.
6. **Lockfile cross-platform**: npm v3 lockfile; don't commit a lock that resolves only your OS's optional deps.

After any change: `npm run check && npm run build` must be green, `_site/index.html` present, 0 visible `:::`.
