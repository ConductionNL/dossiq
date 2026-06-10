# Tasks: docs-product-pages

This is a `kind: config` change — no PHP, no Vue, no OpenRegister
schema changes. All tasks produce documentation files and
`docusaurus.config.js` edits.

## Folder taxonomy

- [~] **T1** — Create `docs/Features/` folder with `_category_.json` — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `_category_.json` must contain `"label": "Features"` and
    `"position": 2`
  - Move or create at least one feature page stub under `Features/`
  - Verify no `features/` (lowercase) folder remains
  - files: `docs/Features/_category_.json`
  - spec_ref: REQ-DOCS-001

- [~] **T2** — Rename `docs/tutorials/` to `docs/user-guide/` and — deferred to downstream cycle / fleet-wide adoption (handoff)
  add `admin/` and `user/` subdirs with their own `_category_.json`
  - `docs/user-guide/_category_.json`: `"label": "Gebruikershandleiding"`, `"position": 3`
  - `docs/user-guide/admin/_category_.json`: `"label": "Beheerder"`
  - `docs/user-guide/user/_category_.json`: `"label": "Gebruiker"`
  - Add `@docusaurus/plugin-client-redirects` entries for any former
    `/tutorials/` URLs in `docusaurus.config.js`
  - files: `docs/user-guide/`, `docusaurus.config.js`
  - spec_ref: REQ-DOCS-001

- [~] **T3** — Create `docs/Technical/` folder with `_category_.json` — deferred to downstream cycle / fleet-wide adoption (handoff)
  and move any root-level architecture/ADR markdown files into it
  - `_category_.json`: `"label": "Technisch"`, `"position": 4`
  - `ARCHITECTURE.md` (if present at root) moves to
    `docs/Technical/architecture.md`
  - files: `docs/Technical/_category_.json`, `docs/Technical/architecture.md`
  - spec_ref: REQ-DOCS-001

- [~] **T4** — Create `docs/UseCases/` stub section — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `docs/UseCases/_category_.json`: `"label": "Gebruiksscenarios"`,
    `"position": 5`
  - `docs/UseCases/index.md` with `draft: true` frontmatter and
    placeholder prose in Dutch
  - files: `docs/UseCases/_category_.json`, `docs/UseCases/index.md`
  - spec_ref: REQ-DOCS-001

- [~] **T5** — Create `docs/Integrations/` stub section — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `docs/Integrations/_category_.json`: `"label": "Integraties"`,
    `"position": 6`
  - `docs/Integrations/index.md` with `draft: true` frontmatter and
    placeholder prose in Dutch
  - files: `docs/Integrations/_category_.json`, `docs/Integrations/index.md`
  - spec_ref: REQ-DOCS-001

## Entry-point and installation docs

- [~] **T6** — Create `docs/index.md` with required frontmatter — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Frontmatter: `id: intro`, `title: Introduction`,
    `sidebar_position: 1`
  - Body: brief Procest introduction in English with links to
    Installation, user-guide, and admin sections
  - files: `docs/index.md`
  - spec_ref: REQ-DOCS-002

- [~] **T7** — Create `docs/installation.md` — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Cover: prerequisites (Nextcloud 28+, PHP 8.1+, OpenRegister),
    App Store installation steps, post-install configuration,
    and troubleshooting
  - Use Dutch headings where locale context suggests it
    (`## Vereisten`, `## Installatie`, `## Configuratie`,
    `## Problemen oplossen`)
  - No em-dash characters
  - files: `docs/installation.md`
  - spec_ref: REQ-DOCS-003

## Redocusaurus plugin

- [~] **T8** — Add `redocusaurus@^2.0.0` to the documentation site — deferred to downstream cycle / fleet-wide adoption (handoff)
  dependencies
  - Run `npm install redocusaurus@^2` (or equivalent) in the docs
    site directory
  - Verify `package.json` shows `"redocusaurus": "^2.0.0"` (or
    compatible semver)
  - files: `package.json`, `package-lock.json`
  - spec_ref: REQ-DOCS-004

- [~] **T9** — Wire `redocusaurus` plugin in `docusaurus.config.js` — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Add plugin entry pointing `spec` to `openapi.yaml` (or the
    repo's canonical OpenAPI spec path) and `route` to `/api`
  - Add `{ label: 'API Documentation', to: '/api', position: 'right' }`
    to the navbar items array
  - If `openapi.yaml` does not yet exist, commit a minimal stub so
    the build does not fail
  - files: `docusaurus.config.js`, `openapi.yaml` (stub if needed)
  - spec_ref: REQ-DOCS-004

## Locale

- [~] **T10** — Add Dutch locale to `docusaurus.config.js` — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Set `i18n.locales` to `['en', 'nl']`
  - Keep `i18n.defaultLocale` as `'en'`
  - Add SSR escape hatch to build script or `docusaurus.config.js`
    per ADR-030 (e.g. `onBrokenMarkdownLinks: 'warn'` and a
    `try/catch` wrapper around the nl build step in CI)
  - files: `docusaurus.config.js`
  - spec_ref: REQ-DOCS-005

## Em-dash sweep

- [~] **T11** — Sweep all `docs/**/*.md` files for em-dash characters — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Run `git grep -E '—' docs/` to identify occurrences
  - For each occurrence, replace with contextually correct ASCII
    equivalent (`-`, `--`, or `:`)
  - Run `git grep -E '—' docs/` again to confirm zero matches
  - files: any `docs/**/*.md` file containing `—`
  - spec_ref: REQ-DOCS-006

## Verification

- [~] **T12** — Build the Docusaurus site locally and confirm — deferred to downstream cycle / fleet-wide adoption (handoff)
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
