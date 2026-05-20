---
kind: config
depends_on: []
chain: []
---

# Proposal: docs-product-pages

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

The Procest documentation site is built on Docusaurus and served via
the `@conduction/docusaurus-preset` fleet standard. The current site
does not conform to the canonical folder taxonomy the preset mandates:
top-level folders use inconsistent casing, tutorials live under
`/tutorials/` instead of `/user-guide/`, legacy root-level markdown
files are scattered rather than grouped under `Technical/`, and the
`UseCases/` and `Integrations/` sections are entirely absent.

Additionally:

- There is no `docs/index.md` with the required Docusaurus frontmatter
  to serve as the sidebar entry point.
- The `redocusaurus@^2.0.0` plugin is not wired up, so the interactive
  API documentation is unreachable from the navbar.
- The `locales` config does not declare `'nl'`, which breaks the
  fleet-standard SSR escape hatch documented in ADR-030.
- Em-dash characters (`—`) appear in several markdown files, which
  breaks automated style checks enforced across all Conduction
  documentation sites.

These gaps make the Procest docs site non-compliant with the fleet
standard and prevent automated tooling (sidebar generation, API
doc rendering, locale switching, prose linting) from working as
expected.

## What changes

1. **Canonical folder taxonomy under `docs/`**

   | Folder | Description |
   |---|---|
   | `Features/` | Curated product feature pages (capital F) |
   | `user-guide/` | Step-by-step tutorials with `admin/` and `user/` subdirs |
   | `Technical/` | Architecture, design decisions, implementation guides |
   | `UseCases/` | Use-case narratives (may be stubs with `draft: true`) |
   | `Integrations/` | Integration guides (may be stubs with `draft: true`) |

2. **Root index document** — `docs/index.md` with frontmatter
   `id: intro`, `title: Introduction`, `sidebar_position: 1`.

3. **Installation guide** — `docs/installation.md` covering
   prerequisites, App Store installation, post-install configuration,
   and troubleshooting.

4. **Redocusaurus plugin** — `redocusaurus@^2.0.0` mounted at route
   `/api` with an "API Documentation" navbar link in
   `docusaurus.config.js`.

5. **Dutch locale declaration** — `locales: ['en', 'nl']` added to
   the `i18n` section of `docusaurus.config.js` (with SSR-failure
   escape hatch per ADR-030).

6. **Em-dash sweep** — all em-dash characters (`—`) in `docs/**/*.md`
   replaced with their ASCII equivalents (`-` or `--`) so that
   `git grep -E '—' docs/` returns no output.

## Impact

- **Files changed**: `docusaurus.config.js`, `docs/index.md` (new),
  `docs/installation.md` (new), folder renames and stub files across
  `docs/Features/`, `docs/user-guide/`, `docs/Technical/`,
  `docs/UseCases/`, `docs/Integrations/`.
- **No code changes**: no PHP, no Vue, no Nextcloud controllers.
- **No data model changes**: no OpenRegister schemas or objects are
  added or modified.
- **Breaking change for existing URLs**: the rename of `tutorials/` to
  `user-guide/` changes existing sidebar links. Docusaurus redirects
  are added per-page using the `@docusaurus/plugin-client-redirects`
  pattern if inbound links from external sources exist.

## Out of scope

- Full prose authoring for UseCases and Integrations stubs
  (stubs with `draft: true` are sufficient for this change).
- Translation of existing docs into Dutch (locale declaration
  enables the locale but translations land in a follow-up change).
- New feature documentation beyond what exists (feature docs are
  owned by individual feature change specs).
- CI integration for the em-dash gate (that is a global CI change
  in the fleet repo, not specific to procest).

## Reviewer gates this change should pass

- `Features/` (capital F) is the top-level folder name — not
  `features/`.
- `user-guide/` URL prefix replaces any `tutorials/` URL prefix.
- `docs/index.md` contains `id: intro`, `title: Introduction`,
  and `sidebar_position: 1` frontmatter.
- `docusaurus.config.js` contains `redocusaurus` in plugins and an
  "API Documentation" navbar item pointing to `/api`.
- `docusaurus.config.js` `i18n.locales` includes both `'en'` and
  `'nl'`.
- `git grep -E '—' docs/` returns empty output.
