---
kind: config
depends_on: []
chain: []
---

# Proposal: docs-product-pages-conformance

**Status:** proposed
**Scope:** procest
**Owner:** Conduction BV — Procest team

## Why

Procest's documentation site does not conform to the canonical product-pages
structure defined by `@conduction/docusaurus-preset` and the design-system
preview (commit `411db7e`). The audit of 2026-05-13 identified:

- Wrong-case folder names (`features/` vs `Features/`, `tutorials/` vs `user-guide/`)
- Six legacy root-level markdown files that belong in a `Technical/` subfolder
- No `installation.md` — users have no canonical install path
- No Redocusaurus API route — API documentation is unreachable from the site
- Em-dash violations (`—`) across 12 files that fail the fleet-wide prose gate
- No `nl` locale configured — violates ADR-007 minimum language requirement

Without structural conformance the site fails the fleet-wide product-pages
standard and cannot serve as a reference for government procurement audiences.

Upstream already applied: preset bump `^1.5.1` → `^2.6.1` (PR #433) and
tutorial content fills (PR #437). Both are out of scope for this change.

## What changes

**Folder renames (git mv — preserves history)**
- `docs/features/` → `docs/Features/` (44 feature pages + README.md)
- `docs/tutorials/` → `docs/user-guide/` (11 tutorial pages in admin/ + user/ subdirs, plus all screenshots)

**New Technical/ folder**
- `docs/ARCHITECTURE.md` → `docs/Technical/architecture.md`
- `docs/DESIGN-REFERENCES.md` → `docs/Technical/design-decisions.md`
- `docs/development.md` → `docs/Technical/development-guide.md`
- `docs/zgw-implementation.md` → `docs/Technical/zgw-spec.md`
- `docs/GOVERNMENT-FEATURES.md` → `docs/Technical/government-compliance.md`
- `docs/FEATURES.md` → `docs/Technical/market-analysis.md`

**Root document**
- `docs/README.md` → `docs/index.md` with Docusaurus frontmatter
  (`id: intro`, `title: Introduction`, `sidebar_position: 1`)

**New files**
- `docs/installation.md` — prerequisites, App Store install, post-install
  config (register + case-type setup + ZGW API endpoint mapping),
  troubleshooting section
- `docs/UseCases/_category_.json` + `docs/UseCases/index.md` stub
  (`draft: true`, content tracked in issue #440)
- `docs/Integrations/_category_.json` + `docs/Integrations/index.md` stub
  (`draft: true`, content tracked in issue #440)
- `docs/static/oas/procest.json` — minimal valid OAS shim (real spec via issue #442)
- `_category_.json` files for `Features/`, `user-guide/`, `Technical/`
  with explicit `position` values to pin sidebar order

**Config changes**
- `docs/package.json`: add `"redocusaurus": "^2.0.0"`
- `docs/docusaurus.config.js`: Redocusaurus plugin (`/api` route),
  "API Documentation" navbar item, `i18n.locales: ['en', 'nl']`

**Content fixes**
- All em-dash characters (`—`) replaced across 12 docs files
- Internal cross-links in moved Technical/ files updated to new relative paths

## New capabilities

- `docs-product-pages`: Canonical product-pages folder structure, installation
  guide, Redocusaurus API mount, UseCases/Integrations stubs, nl locale,
  em-dash-clean prose

## Impact

- `docs/` folder: approximately 60 files renamed or moved (git mv preserves history)
- `docs/docusaurus.config.js`: Redocusaurus plugin + navbar item + nl locale
- `docs/package.json`: one new devDependency
- `docs/static/oas/procest.json`: new placeholder file (4 lines)
- All internal links in moved Technical/ files updated to relative paths
- No PHP, Vue, or backend code changes
- No OpenRegister schema changes
- Downstream: CI docs-deploy workflow picks up changes on merge to `development`

## Out of scope

- Writing actual NL-translated markdown (issue #441)
- Writing real content for `UseCases/` or `Integrations/` (issue #440)
- Writing the real OpenAPI spec (issue #442)
- Any PHP, Vue, or backend code changes
- Changing the landing page `src/pages/index.js` — already brand-compliant
- Preset bump (done in PR #433) and tutorial content fills (done in PR #437)

## Reviewer gates this change should pass

- All folder names match the canonical product-pages taxonomy:
  `Features/`, `user-guide/`, `Technical/`, `UseCases/`, `Integrations/`
- `git grep -E '—' docs/` returns 0 matches (em-dash gate)
- `npm run build` in `docs/` exits 0 (or nl locale reverted with documented
  escape hatch per D5)
- No `lib/Db/`, no `*.php`, no `*.vue` files are added or modified
- Every renamed file was moved with `git mv` (history preserved — verify
  with `git log --follow docs/Technical/architecture.md`)
- `docs/installation.md` covers all four sections: prerequisites, install,
  post-install config (register + ZGW), troubleshooting
