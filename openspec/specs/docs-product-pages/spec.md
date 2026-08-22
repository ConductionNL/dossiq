---
status: done
---

**Capability**: docs-product-pages
**Status**: in-progress
**OpenSpec changes**: docs-product-pages-conformance (2026-05-13)

## Purpose

@e2e exclude Documentation site folder structure; not a Nextcloud app feature, no Playwright UI surface.

## Summary

Canonical product-pages documentation structure for the Dossiq documentation site, conforming to the `@conduction/docusaurus-preset` fleet standard.
## Requirements
### Requirement: Canonical folder taxonomy

The documentation site SHALL use the following canonical top-level folder structure under `docs/`:
- `Features/` (capital F) — curated product feature pages
- `user-guide/` — step-by-step tutorials with `admin/` and `user/` subdirs
- `Technical/` — architecture, design decisions, implementation guides
- `UseCases/` — use-case narratives (may be stubs with `draft: true`)
- `Integrations/` — integration guides (may be stubs with `draft: true`)

#### Scenario: Features folder is capital-F

- **WHEN** a user browses the documentation sidebar
- **THEN** the Features section appears as `Features/` (not `features/`)

#### Scenario: Tutorials renamed to user-guide

- **WHEN** a user navigates to a tutorial
- **THEN** the URL contains `/user-guide/` (not `/tutorials/`)

#### Scenario: Legacy root MDs are in Technical/

- **WHEN** a developer searches for ARCHITECTURE.md
- **THEN** the file is found at `Technical/architecture.md`, not at the docs root

#### Scenario: UseCases and Integrations stubs exist

- **WHEN** Docusaurus builds the sidebar
- **THEN** `UseCases/` and `Integrations/` appear as sections

---

### Requirement: Root index document

The documentation site SHALL have a `docs/index.md` with Docusaurus frontmatter (`id: intro`, `title: Introduction`, `sidebar_position: 1`) as the entry point for the sidebar.

#### Scenario: index.md renders as site introduction

- **WHEN** a user opens the Documentation section
- **THEN** the first page shown is the Introduction page

---

### Requirement: Installation guide

The documentation site SHALL provide a `docs/installation.md` covering prerequisites, App Store installation, post-install configuration, and troubleshooting.

#### Scenario: Installation guide is accessible from sidebar

- **WHEN** a user navigates the Documentation sidebar
- **THEN** an "Installation" entry is visible

---

### Requirement: Redocusaurus API documentation route

The documentation site SHALL mount `redocusaurus@^2.0.0` at route `/api` with an "API Documentation" navbar link.

#### Scenario: API Documentation navbar item exists

- **WHEN** a user views the documentation navbar
- **THEN** an "API Documentation" link is present

---

### Requirement: Dutch locale declared

The documentation site SHALL declare `locales: ['en', 'nl']` (with SSR-failure escape hatch per ADR-030).

#### Scenario: nl locale declared in config

- **WHEN** a developer reads `docusaurus.config.js`
- **THEN** the `locales` array contains both `'en'` and `'nl'`

---

### Requirement: Em-dash-free documentation prose

All markdown files under `docs/` SHALL contain zero em-dash characters (`—`).

#### Scenario: Em-dash gate passes

- **WHEN** `git grep -E '—' docs/` is run
- **THEN** the command returns no output

### Requirement: REQ-DOCS-001 Canonical folder taxonomy under `docs/`

The documentation site SHALL use the following canonical top-level
folder structure under `docs/`:

| Folder | Purpose | May contain stubs |
|---|---|---|
| `Features/` (capital F) | Curated product feature pages | No |
| `user-guide/` | Step-by-step tutorials with `admin/` and `user/` subdirs | No |
| `Technical/` | Architecture, design decisions, implementation guides | No |
| `UseCases/` | Use-case narratives | Yes (`draft: true`) |
| `Integrations/` | Integration guides | Yes (`draft: true`) |

Each top-level folder MUST contain a `_category_.json` file with a
`label` and `position` field so Docusaurus can generate the sidebar
automatically.

#### Scenario: Features folder is capital-F

- **GIVEN** the Dossiq documentation site is built with Docusaurus
- **WHEN** a user browses the documentation sidebar
- **THEN** the Features section MUST appear with the exact label
  `Features` (capital F)
- **AND** the folder on disk MUST be named `Features/` (not `features/`)

#### Scenario: Tutorials renamed to user-guide

- **GIVEN** a page that was previously accessible at a `/tutorials/`
  URL path
- **WHEN** a user navigates to the equivalent URL under `/user-guide/`
- **THEN** the page MUST render correctly
- **AND** any legacy `/tutorials/` URLs MUST redirect to their
  `/user-guide/` equivalents via `@docusaurus/plugin-client-redirects`

#### Scenario: Legacy root MDs are in Technical/

- **GIVEN** the Dossiq repository
- **WHEN** a developer searches for the architecture reference document
- **THEN** the file MUST be found at `docs/Technical/architecture.md`
- **AND** no equivalent document MUST exist at the `docs/` root

#### Scenario: UseCases and Integrations stubs exist

- **GIVEN** the Docusaurus site is built
- **WHEN** Docusaurus processes the `docs/` directory
- **THEN** `UseCases/` and `Integrations/` MUST appear as sections in
  the sidebar
- **AND** each section MUST contain at least one index page with
  `draft: true` in its frontmatter

---

### Requirement: REQ-DOCS-002 Root index document

The documentation site SHALL have a `docs/index.md` with the
following Docusaurus frontmatter:

```
id: intro
title: Introduction
sidebar_position: 1
```

This file serves as the entry point for the documentation sidebar
and MUST be the first item rendered when a user opens the
Documentation section.

#### Scenario: index.md renders as site introduction

- **GIVEN** the Dossiq documentation site is running
- **WHEN** a user opens the Documentation section
- **THEN** the first page shown MUST be the Introduction page
- **AND** the page title MUST be "Introduction"
- **AND** the sidebar MUST list "Introduction" as its first entry

#### Scenario: index.md frontmatter is complete

- **GIVEN** the file `docs/index.md`
- **WHEN** its frontmatter is parsed
- **THEN** the fields `id`, `title`, and `sidebar_position` MUST all
  be present
- **AND** `id` MUST equal `intro`
- **AND** `sidebar_position` MUST equal `1`

---

### Requirement: REQ-DOCS-003 Installation guide

The documentation site SHALL provide `docs/installation.md` covering
the following topics in order:

1. Prerequisites (Nextcloud version, PHP version, OpenRegister
   dependency)
2. App Store installation steps
3. Post-install configuration
4. Troubleshooting

#### Scenario: Installation guide is accessible from sidebar

- **GIVEN** the Dossiq documentation site is running
- **WHEN** a user navigates the Documentation sidebar
- **THEN** an "Installation" entry MUST be visible in the sidebar
- **AND** clicking "Installation" MUST render the installation guide
  page

#### Scenario: Installation guide covers all required sections

- **GIVEN** the file `docs/installation.md`
- **WHEN** its content is inspected
- **THEN** the document MUST contain sections addressing:
  - System prerequisites (Nextcloud and PHP minimum versions)
  - Step-by-step App Store installation
  - Post-install configuration steps
  - A troubleshooting section or link

---

### Requirement: REQ-DOCS-004 Redocusaurus API documentation route

The documentation site SHALL mount `redocusaurus@^2.0.0` as a
Docusaurus plugin, configured to render the Dossiq OpenAPI spec
at route `/api`. The Docusaurus navbar SHALL include an
"API Documentation" link pointing to `/api`.

The plugin version constraint is `^2.0.0` (semver caret — minor
and patch upgrades are permitted, major is not).

#### Scenario: API Documentation navbar item exists

- **GIVEN** the Dossiq documentation site is running
- **WHEN** a user views any page in the documentation
- **THEN** an "API Documentation" link MUST be present in the navbar
- **AND** clicking that link MUST navigate to `/api`

#### Scenario: /api renders the OpenAPI spec

- **GIVEN** a valid `openapi.yaml` is present in the repository root
- **WHEN** a user navigates to `/api`
- **THEN** Redoc MUST render the interactive API documentation
- **AND** the page MUST not return a 404 or blank screen

#### Scenario: redocusaurus version constraint is satisfied

- **GIVEN** the `package.json` of the documentation site
- **WHEN** the `redocusaurus` dependency is inspected
- **THEN** the version constraint MUST start with `^2.0.0`
- **AND** `npm ls redocusaurus` MUST resolve to a `2.x.x` version

---

### Requirement: REQ-DOCS-005 Dutch locale declared

The documentation site SHALL declare `locales: ['en', 'nl']` in the
`i18n` section of `docusaurus.config.js`. The `defaultLocale` SHALL
remain `'en'`. An SSR-failure escape hatch MUST be present per
ADR-030 so that a failing Dutch build does not block the English
production deployment.

#### Scenario: nl locale declared in config

- **GIVEN** the file `docusaurus.config.js`
- **WHEN** a developer reads the `i18n` configuration block
- **THEN** the `locales` array MUST contain both `'en'` and `'nl'`
- **AND** `defaultLocale` MUST be `'en'`

#### Scenario: SSR escape hatch is in place

- **GIVEN** the build script for the documentation site
- **WHEN** the `nl` locale build fails with an SSR error
- **THEN** the build MUST fall back gracefully to the `en`-only build
- **AND** the failure MUST be logged as a warning, not a hard error

---

### Requirement: REQ-DOCS-006 Em-dash-free documentation prose

All markdown files under `docs/` SHALL contain zero em-dash
characters (`—`). This requirement applies to all files regardless
of authoring locale.

The gate command is:

```bash
git grep -E '—' docs/
```

A passing gate returns no output and exits with code 0.

#### Scenario: Em-dash gate passes

- **GIVEN** the Dossiq repository with all `docs/` files present
- **WHEN** `git grep -E '—' docs/` is run
- **THEN** the command MUST return no output
- **AND** the exit code MUST be 0

#### Scenario: Replacement characters are contextually correct

- **GIVEN** any former em-dash occurrence in `docs/`
- **WHEN** the replacement is applied
- **THEN** the replacement character MUST be chosen based on context:
  - A hyphen (`-`) for compound modifiers and list separators
  - A double-hyphen (`--`) for ranges or pauses
  - A colon (`:`) when the em-dash introduces a definition
- **AND** the surrounding prose MUST remain grammatically correct

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
2. **App Store installation** — install Dossiq from the Nextcloud App Store;
   enable the app via the Apps admin panel
3. **Post-install configuration** — dossiq register is created automatically
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
  endpoint mapping in the Dossiq Admin Settings panel

#### Scenario: Installation guide documents the register repair step

- **GIVEN** a user installs Dossiq and finds the register is missing
- **WHEN** they follow the troubleshooting section
- **THEN** they MUST find the instruction to re-run the Nextcloud
  repair step to recreate the dossiq register

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
      specs: [{ spec: 'static/oas/dossiq.json', route: '/api/' }],
    },
  ],
],
```

The navbar MUST include:

```js
{ to: '/api/', label: 'API Documentation', position: 'left' }
```

`docs/static/oas/dossiq.json` MUST exist with a minimal valid OAS
shim (`openapi: 3.0.0`, `info.title: Dossiq`, `info.version: 0.0.0`,
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

- **GIVEN** `docs/static/oas/dossiq.json` exists
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

- **GIVEN** the title was `# Dossiq — Feature Analysis`
- **WHEN** the em-dash sweep is complete
- **THEN** the title MUST read `# Dossiq: Feature Analysis`

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

