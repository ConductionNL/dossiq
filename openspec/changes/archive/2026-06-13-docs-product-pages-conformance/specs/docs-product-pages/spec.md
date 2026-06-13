# Spec: docs-product-pages

**Status:** proposed
**Scope:** procest
**Tier:** docs-conformance
**Depends on:** (none — docs-only change, no OpenRegister entities, no backend dependencies)

## ADDED Requirements

### Requirement: REQ-DPP-001 Canonical folder taxonomy SHALL match the product-pages standard

The `docs/` directory MUST use the following canonical top-level folder
structure as defined by `@conduction/docusaurus-preset`:

- `Features/` (capital F) — curated product feature pages
- `user-guide/` — step-by-step tutorials with `admin/` and `user/` subdirs
- `Technical/` — architecture, design decisions, implementation guides, strategic content
- `UseCases/` — use-case narratives (stubs with `draft: true` until issue #440 lands)
- `Integrations/` — integration guides (stubs with `draft: true` until issue #440 lands)

All renames MUST be performed with `git mv` to preserve file history.
Each folder MUST include a `_category_.json` with an explicit `position`
value to prevent Docusaurus alphabetical re-ordering after case renames.

#### Scenario: Features folder uses canonical casing

- **GIVEN** the documentation site is built with `npm run build`
- **WHEN** a user browses the generated output
- **THEN** the feature pages MUST be served under the path `/Features/`
  (capital F), not `/features/`

#### Scenario: Tutorials folder is renamed to user-guide

- **GIVEN** the migration is complete
- **WHEN** a user navigates to any tutorial page
- **THEN** the URL MUST contain `/user-guide/` and MUST NOT contain
  `/tutorials/`

#### Scenario: Legacy root MDs are accessible under Technical/

- **GIVEN** a developer searches for the architecture document
- **WHEN** they look in `docs/`
- **THEN** `ARCHITECTURE.md` MUST NOT exist at the root; it MUST be
  found at `docs/Technical/architecture.md` with its full history
  intact (verifiable via `git log --follow`)

#### Scenario: UseCases and Integrations stubs appear in the sidebar

- **GIVEN** the documentation site is built
- **WHEN** a user inspects the sidebar
- **THEN** both `Use Cases` and `Integrations` sections MUST appear
  (rendered from their draft index stubs)

#### Scenario: Screenshot files survive the tutorials rename

- **GIVEN** PR #437 added PNG screenshots to `docs/tutorials/user/`
  and `docs/tutorials/admin/`
- **WHEN** `git mv docs/tutorials docs/user-guide` is executed
- **THEN** every PNG file MUST be present under `docs/user-guide/`
  and the count MUST match the pre-rename count

---

### Requirement: REQ-DPP-002 Root document SHALL be `index.md` with Docusaurus frontmatter

`docs/index.md` MUST exist (renamed from `docs/README.md` via `git mv`)
and MUST contain the following frontmatter block:

```yaml
---
id: intro
title: Introduction
sidebar_position: 1
---
```

`docs/README.md` MUST NOT exist after the migration.

#### Scenario: Introduction page renders as the first sidebar entry

- **GIVEN** the documentation site is built
- **WHEN** a user opens the Documentation section
- **THEN** the first page shown MUST be "Introduction" (sourced from
  `docs/index.md`) and it MUST appear at sidebar position 1

#### Scenario: README.md is removed from the docs root

- **GIVEN** the migration is complete
- **WHEN** a developer lists files in `docs/`
- **THEN** `README.md` MUST NOT appear; only `index.md` MUST be present
  as the root document, with history carried over from `README.md`

---

### Requirement: REQ-DPP-003 Installation guide SHALL cover prerequisites, install, post-install config, and troubleshooting

`docs/installation.md` MUST exist with `sidebar_position: 2` frontmatter
and MUST contain the following four sections:

1. **Prerequisites** — Nextcloud 28 or higher; OpenRegister app enabled
2. **App Store installation** — install Procest from the Nextcloud App Store;
   enable the app via the Apps admin panel
3. **Post-install configuration** — procest register is created automatically
   via the repair step; case-type configuration; ZGW API endpoint mapping
   in the Admin Settings panel
4. **Troubleshooting** — register not found: re-run the Nextcloud repair step;
   ZGW 400 errors: verify endpoint URLs in Admin Settings

#### Scenario: Installation guide is reachable from the documentation sidebar

- **GIVEN** the documentation site is built
- **WHEN** a user navigates the Documentation sidebar
- **THEN** an "Installation" entry MUST be visible at the top level
  immediately after the Introduction entry (sidebar_position: 2)

#### Scenario: Installation guide documents the ZGW endpoint configuration step

- **GIVEN** a user follows the installation guide for the first time
- **WHEN** they reach the post-install configuration section
- **THEN** they MUST find instructions for configuring the ZGW API
  endpoint mapping in the Procest Admin Settings panel

#### Scenario: Installation guide documents the register repair step

- **GIVEN** a user installs Procest and finds the register is missing
- **WHEN** they follow the troubleshooting section
- **THEN** they MUST find the instruction to re-run the Nextcloud
  repair step to recreate the procest register

---

### Requirement: REQ-DPP-004 Redocusaurus API documentation SHALL be mounted at `/api` with a navbar entry

`docs/package.json` MUST declare `"redocusaurus": "^2.0.0"` as a
dependency.

`docs/docusaurus.config.js` MUST configure the Redocusaurus plugin:

```js
plugins: [
  [
    'redocusaurus',
    {
      specs: [{ spec: 'static/oas/procest.json', route: '/api/' }],
    },
  ],
],
```

The navbar MUST include:

```js
{ to: '/api/', label: 'API Documentation', position: 'left' }
```

`docs/static/oas/procest.json` MUST exist with a minimal valid OAS
shim (`openapi: 3.0.0`, `info.title: Procest`, `info.version: 0.0.0`,
`paths: {}`) until the real spec lands via issue #442.

#### Scenario: API Documentation navbar item is visible

- **GIVEN** the documentation site is built and served
- **WHEN** a user views any page
- **THEN** an "API Documentation" link MUST be present in the top navbar

#### Scenario: `/api` route resolves without build error

- **GIVEN** the OAS shim is in place
- **WHEN** `npm run build` is executed in `docs/`
- **THEN** the build MUST exit 0 AND the `/api/` directory MUST exist
  in the build output

#### Scenario: OAS shim is a structurally valid OpenAPI 3.0 document

- **GIVEN** `docs/static/oas/procest.json` exists
- **WHEN** its JSON is parsed
- **THEN** it MUST contain `openapi`, `info`, and `paths` top-level
  keys with valid values so Redocusaurus does not throw on load

---

### Requirement: REQ-DPP-005 Dutch locale SHALL be declared in `docusaurus.config.js`

`docs/docusaurus.config.js` MUST declare `i18n.locales: ['en', 'nl']`
and MUST include `nl: { label: 'Nederlands' }` in `i18n.localeConfigs`.

If `npm run build` fails with an SSR error caused by an empty `i18n/nl/`
directory, the implementation MUST revert `locales` to `['en']` and add
the following comment in the config:

```js
/* nl reverted: SSR error with empty i18n/nl/ —
   re-enable once translation backfill lands (issue #441) */
```

The reversion is acceptable as a known escape hatch (ADR-030).

#### Scenario: nl locale is declared in the config

- **GIVEN** the migration is complete
- **WHEN** a developer reads `docs/docusaurus.config.js`
- **THEN** the `locales` array MUST contain `'nl'` (or a comment
  per the escape hatch if SSR failed) and `localeConfigs` MUST
  include a `nl` entry

#### Scenario: Build passes after locale configuration

- **GIVEN** the locale config is applied
- **WHEN** `npm run build` runs in `docs/`
- **THEN** the build MUST exit 0 — either with nl locale active,
  or with the nl locale reverted per the escape hatch

---

### Requirement: REQ-DPP-006 Documentation prose SHALL contain zero em-dash characters

All markdown files under `docs/` MUST contain zero em-dash characters
(`—`). The gate command `git grep -E '—' docs/` (excluding `node_modules/`
and `build/`) MUST return no output (exit code 1 with zero matches).

Em-dashes are replaced according to context (design decision D6):
- Prose clause separators (`' — '`): replaced with `', '` or `': '`
- Code-reference bullets (`` `endpoint()` — desc ``): replaced with `': '`
- HTML comment templates (`{{TODO: ... — see ...}}`): replaced with `'-'`

The 12 known affected files are:
- `docs/Technical/market-analysis.md` (11 hits including title)
- `docs/Features/README.md`
- `docs/Features/administration.md`
- `docs/Features/zgw-apis.md`
- `docs/Features/bezwaar-beroep-workflow.md`
- `docs/Features/workflow-engine-enhancement.md`
- `docs/Features/vth-workflow-configuration.md`
- `docs/Features/besluitvorming-workflow.md`
- `docs/Features/doorlooptijd-dashboard.md`
- `docs/Features/deelzaak-support.md`
- `docs/Features/app-scaffold.md`
- `docs/Features/gis-integration.md`
- `docs/user-guide/` HTML comments

#### Scenario: Em-dash gate passes after implementation

- **GIVEN** all em-dash replacements have been applied
- **WHEN** `git grep -E '—' docs/` is run (excluding node_modules and build)
- **THEN** the command MUST return no output and exit with code 1
  (meaning zero matches found)

#### Scenario: Feature page bullets use colon notation after replacement

- **GIVEN** a feature page previously contained `` `endpoint()` — description ``
- **WHEN** the em-dash sweep is complete
- **THEN** the same line MUST read `` `endpoint()`: description `` or
  `` `endpoint()` - description `` and the em-dash character MUST be absent

#### Scenario: market-analysis.md title uses colon not em-dash

- **GIVEN** the title was `# Procest — Feature Analysis`
- **WHEN** the em-dash sweep is complete
- **THEN** the title MUST read `# Procest: Feature Analysis`

---

### Requirement: REQ-DPP-007 Documentation site SHALL build cleanly after all structural changes

After applying all tasks (REQ-DPP-001 through REQ-DPP-006), `npm install --legacy-peer-deps && npm run build` in `docs/` MUST exit 0.

The build output MUST include the following routes:
- `/Features/` — feature pages
- `/user-guide/` — tutorial pages
- `/Technical/` — technical reference
- `/api/` — Redocusaurus API documentation

No route previously served under `/features/` or `/tutorials/` SHOULD
continue to exist in the output (paths changed by the renames).

#### Scenario: Full build succeeds end-to-end

- **GIVEN** all migration tasks are complete
- **WHEN** `cd docs && npm install --legacy-peer-deps && npm run build`
  is executed with a 10-minute timeout
- **THEN** the process MUST exit 0 and the four canonical routes MUST
  be present in the build output directory
