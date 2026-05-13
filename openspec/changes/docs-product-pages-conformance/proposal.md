## Why

Procest's documentation site does not conform to the canonical product-pages structure defined by `@conduction/docusaurus-preset` and the design-system preview (commit `411db7e`). The audit of 2026-05-13 identified missing canonical folders, misnamed folders, legacy root-level markdown files that belong in a `Technical/` subfolder, missing `installation.md`, missing Redocusaurus API route, and em-dash violations across 12 files. Without structural conformance, the site fails the fleet-wide product-pages standard and cannot serve as a reference for government procurement audiences.

## What Changes

- **Rename** `docs/features/` → `docs/Features/` (44 feature pages + README.md, preserving content)
- **Rename** `docs/tutorials/` → `docs/user-guide/` (11 tutorial pages in admin/ + user/ subdirs, preserving content and screenshots)
- **Create** `docs/Technical/` folder and move 6 legacy root MDs into it:
  - `ARCHITECTURE.md` → `Technical/architecture.md`
  - `DESIGN-REFERENCES.md` → `Technical/design-decisions.md`
  - `development.md` → `Technical/development-guide.md`
  - `zgw-implementation.md` → `Technical/zgw-spec.md`
  - `GOVERNMENT-FEATURES.md` → `Technical/government-compliance.md`
  - `FEATURES.md` → `Technical/market-analysis.md` (strategic analyst content, not a curated feature page)
- **Rename** `docs/README.md` → `docs/index.md` with proper Docusaurus frontmatter
- **Create** `docs/installation.md` with real Nextcloud App Store install steps, prerequisites, post-install config (register + case-type setup + ZGW API endpoint mapping), and troubleshooting
- **Create** `docs/UseCases/` and `docs/Integrations/` stub folders (draft: true, citing issue #440)
- **Add** `redocusaurus@^2.0.0` to `docs/package.json` and configure the `/api` route in `docs/docusaurus.config.js` fed by `docs/static/oas/procest.json`
- **Add** "API Documentation" navbar item
- **Re-enable** `nl` locale in `docs/docusaurus.config.js` (revert to `['en']` with comment if SSR fails, cite #441)
- **Create** `docs/static/oas/procest.json` placeholder OAS shim (real spec via issue #442)
- **Fix** all em-dash violations across 12 docs files to pass the `git grep -E '—' docs/` = 0 gate
- **Update** internal cross-links in moved files

Note: Preset bump `^1.5.1` → `^2.6.1` was already completed upstream in PR #433. Tutorial content fills were done in PR #437. Both are out of scope.

## Capabilities

### New Capabilities

- `docs-product-pages`: Canonical product-pages folder structure, installation guide, Redocusaurus API mount, UseCases/Integrations stubs, nl locale, em-dash-clean prose

### Modified Capabilities

- (none — no existing spec-level requirements change; this is a docs-structure migration)

## Impact

- `docs/` folder structure: ~60 files renamed or moved (git mv preserves history)
- `docs/docusaurus.config.js`: redocusaurus plugin + navbar item + nl locale re-enabled
- `docs/package.json`: add `redocusaurus@^2.0.0`
- `docs/static/oas/procest.json`: new placeholder file
- All internal links in moved Technical/ files: updated to relative paths
- No PHP, Vue, or backend code changes
- No OpenRegister schema changes
- Downstream: CI docs-deploy workflow will pick up the changes on merge to `development`
