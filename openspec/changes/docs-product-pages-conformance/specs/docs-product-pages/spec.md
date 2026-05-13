## ADDED Requirements

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
- **THEN** `UseCases/` and `Integrations/` appear as sections (with draft index stubs)

---

### Requirement: Root index document

The documentation site SHALL have a `docs/index.md` (not `README.md`) with Docusaurus frontmatter (`id: intro`, `title: Introduction`, `sidebar_position: 1`) as the entry point for the sidebar.

#### Scenario: index.md renders as site introduction

- **WHEN** a user opens the Documentation section
- **THEN** the first page shown is the Introduction page (from `index.md`)

---

### Requirement: Installation guide

The documentation site SHALL provide a `docs/installation.md` covering:
1. Prerequisites (Nextcloud 28+, OpenRegister app enabled)
2. App Store installation steps
3. Post-install configuration: register setup, case-type configuration, ZGW API endpoint mapping
4. Basic troubleshooting (common errors and resolutions)

#### Scenario: Installation guide is accessible from sidebar

- **WHEN** a user navigates the Documentation sidebar
- **THEN** an "Installation" entry is visible at the top level

#### Scenario: Installation guide covers ZGW endpoint config

- **WHEN** a user follows the installation guide
- **THEN** they find instructions for configuring the ZGW API endpoint mapping in admin settings

---

### Requirement: Redocusaurus API documentation route

The documentation site SHALL mount `redocusaurus@^2.0.0` at route `/api`, fed by `static/oas/procest.json`. The navbar SHALL include an "API Documentation" link pointing to `/api`.

#### Scenario: API Documentation navbar item exists

- **WHEN** a user views the documentation navbar
- **THEN** an "API Documentation" link is present

#### Scenario: /api route resolves without build error

- **WHEN** `npm run build` is executed
- **THEN** the build exits 0 and the `/api` route is generated (using the placeholder OAS shim until the real spec lands via issue #442)

---

### Requirement: Dutch locale re-enabled

The documentation site SHALL declare `locales: ['en', 'nl']` in `docusaurus.config.js`. If SSR rendering errors occur during `npm run build`, the locale SHALL be reverted to `['en']` with a comment citing issue #441.

#### Scenario: nl locale declared in config

- **WHEN** a developer reads `docusaurus.config.js`
- **THEN** the `locales` array contains both `'en'` and `'nl'`

#### Scenario: Build passes with nl locale declared

- **WHEN** `npm run build` runs
- **THEN** the build exits 0 (or, if SSR fails, the locale is reverted to ['en'] and build exits 0)

---

### Requirement: Em-dash-free documentation prose

All markdown files under `docs/` SHALL contain zero em-dash characters (`—`). The gate `git grep -E '—' docs/` MUST return empty output.

#### Scenario: Em-dash gate passes

- **WHEN** `git grep -E '—' docs/` is run after implementation
- **THEN** the command returns no output (exit code 1, zero matches)
