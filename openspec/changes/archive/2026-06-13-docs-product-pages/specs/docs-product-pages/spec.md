---
status: proposed
---

# Spec: docs-product-pages

**Status:** proposed
**Scope:** procest
**Tier:** MVP
**Depends on:** docusaurus, @conduction/docusaurus-preset, redocusaurus@^2.0.0

## Purpose

Establish the canonical documentation site structure for Procest,
conforming to the `@conduction/docusaurus-preset` fleet standard.
This spec covers folder taxonomy, entry-point document, installation
guide, API documentation plugin, locale declaration, and prose
cleanliness.

No OpenRegister entities are added or modified by this spec.

---

## ADDED Requirements

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

- **GIVEN** the Procest documentation site is built with Docusaurus
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

- **GIVEN** the Procest repository
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

- **GIVEN** the Procest documentation site is running
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

- **GIVEN** the Procest documentation site is running
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
Docusaurus plugin, configured to render the Procest OpenAPI spec
at route `/api`. The Docusaurus navbar SHALL include an
"API Documentation" link pointing to `/api`.

The plugin version constraint is `^2.0.0` (semver caret — minor
and patch upgrades are permitted, major is not).

#### Scenario: API Documentation navbar item exists

- **GIVEN** the Procest documentation site is running
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

- **GIVEN** the Procest repository with all `docs/` files present
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
