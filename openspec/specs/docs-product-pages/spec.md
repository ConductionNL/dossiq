**Capability**: docs-product-pages
**Status**: in-progress
**OpenSpec changes**: docs-product-pages-conformance (2026-05-13)

## Purpose

@e2e exclude Documentation site folder structure; not a Nextcloud app feature, no Playwright UI surface.

## Summary

Canonical product-pages documentation structure for the Procest documentation site, conforming to the `@conduction/docusaurus-preset` fleet standard.

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
