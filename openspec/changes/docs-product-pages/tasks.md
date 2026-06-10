# Tasks: docs-product-pages

> **Build status (hydra audit 2026-06-10).** Almost entirely shipped. `docs/Features/` + `_category_.json`, `docs/user-guide/` + `_category_.json` + `admin/` + `user/` subdirs and their `_category_.json` files all exist on dev. `docs/Features/` contains 30+ feature pages including all the headline ones listed in this spec. The remaining open work is a content sweep + final docusaurus redirect entries — kept as [ ] so an author owns it.

This is a `kind: config` change — no PHP, no Vue, no OpenRegister
schema changes. All tasks produce documentation files and
`docusaurus.config.js` edits.

## Folder taxonomy

- [x] **T1** — `docs/Features/` exists with `_category_.json` (label "Features", position 3) and 30+ feature pages — shipped on dev
- [x] **T2** — `docs/user-guide/` exists with `_category_.json` (label "User Guide", position 2) and `admin/` + `user/` subdirs each with their own `_category_.json` — shipped on dev. No client-redirects entry needed: `tutorials/` was never published on a live site.
- [x] **T3** — `docs/Technical/` exists with `_category_.json` (label "Technical", position 6) and contains multiple architecture docs — shipped on dev
- [x] **T4** — `docs/UseCases/` exists with `_category_.json` (label "Use Cases", position 4) and `index.md` placeholder — shipped on dev
- [x] **T5** — `docs/Integrations/` exists with `_category_.json` (label "Integrations", position 5) and `index.md` placeholder — shipped on dev

## Entry-point and installation docs

- [x] **T6** — `docs/index.md` exists with `id: intro`, `title: Introduction`, `sidebar_position: 1` — shipped on dev
- [x] **T7** — `docs/installation.md` exists with prerequisites + install + configuration sections — shipped on dev (no em-dash; this PR swept the last 9 em-dashes from `docs/Technical/leges-heffingen.md`)

## Redocusaurus plugin

- [x] **T8** — `redocusaurus@^2.0.0` is in `docs/package.json` — shipped on dev
- [x] **T9** — `redocusaurus` plugin is wired in `docs/docusaurus.config.js` — shipped on dev

## Locale

- [x] **T10** — `i18n.locales: ['en', 'nl']` with `defaultLocale: 'en'` in `docs/docusaurus.config.js` — shipped on dev

## Em-dash sweep

- [x] **T11** — Swept the last 9 em-dashes from `docs/Technical/leges-heffingen.md` in this change. `git grep -E '—' docs/` now returns zero matches.

## Verification

- [~] **T12** — Build the Docusaurus site locally and confirm
  - Sidebar shows `Features` (capital F), `user-guide`,
    `Technical`, `UseCases`, `Integrations` sections
  - `docs/index.md` renders as the first sidebar entry "Introduction"
  - "Installation" appears in the sidebar and renders the guide
  - Navbar shows "API Documentation" link
  - Navigating to `/api` renders the Redoc UI
  - `git grep -E '—' docs/` returns no output
  - files: (no new files — verification task)
  - spec_ref: REQ-DOCS-001, REQ-DOCS-002, REQ-DOCS-003, REQ-DOCS-004,
    REQ-DOCS-005, REQ-DOCS-006
