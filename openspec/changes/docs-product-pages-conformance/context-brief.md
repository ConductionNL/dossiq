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



## Design

## Context

Procest's documentation site (`docs/`) is built on `@conduction/docusaurus-preset`. The 2026-05-13 fleet audit identified structural non-conformance against the canonical product-pages spec: wrong-case folder names (`features/` vs `Features/`, `tutorials/` vs `user-guide/`), six legacy root-level MDs that belong in a `Technical/` subfolder, no `installation.md`, no Redocusaurus API mount, and em-dash violations across 12 files.

The migration is entirely mechanical: git renames, file moves, new stub files, config edits, and find-and-replace em-dash fixes. There are no PHP, Vue, or backend code changes.

**Upstream already applied:**
- Preset bump `^1.5.1` → `^2.6.1` (PR #433)
- Tutorial content fills and screenshots (PR #437)

## Goals / Non-Goals

**Goals:**
- Rename `features/` → `Features/` and `tutorials/` → `user-guide/` using `git mv` (preserves history)
- Move 6 legacy root MDs into `Technical/` using `git mv`
- Rename `README.md` → `index.md` with Docusaurus frontmatter
- Create `installation.md` with real install + config steps for procest
- Scaffold `UseCases/` and `Integrations/` stub folders (draft: true)
- Add `redocusaurus@^2.0.0` + configure `/api` route + `static/oas/procest.json` placeholder
- Add "API Documentation" navbar item
- Re-enable `nl` locale (with SSR-failure escape hatch per ADR-030)
- Fix all em-dashes in the docs scope (gate: `git grep -E '—' docs/` = 0)

**Non-Goals:**
- Writing actual NL translated markdown (issue #441)
- Writing real content for `UseCases/` or `Integrations/` (issue #440)
- Writing the real OpenAPI spec (issue #442)
- Any PHP, Vue, or backend code changes
- Changing landing page (`src/pages/index.js`) — already brand-compliant

## Decisions

### D1: Use `git mv` for all renames and moves

`git mv` preserves file history, which matters for the 44 feature files and 11 tutorial files. The alternative (delete + add) would orphan history.

### D2: `FEATURES.md` → `Technical/market-analysis.md` (not `Features/`)

`FEATURES.md` is 360-line strategic/competitive analyst content (market analysis, competitor tables, feature demand matrix). It is NOT a curated end-user feature page. Moving it to `Technical/` separates internal analyst material from the public-facing feature catalogue. The 44 curated feature pages in `features/` are the canonical product docs.

### D3: `README.md` → `index.md` with frontmatter, not deletion

`README.md` has real content (feature table, architecture overview, screenshots). Renaming to `index.md` and adding `sidebar_position: 1` frontmatter makes it the site's landing document in the Docusaurus autogenerated sidebar. Deleting would remove useful navigation content.

### D4: OAS placeholder shim in `static/oas/procest.json`

Redocusaurus requires the OAS file to exist at build time or the build 404s. Shipping a minimal valid OAS `{"openapi":"3.0.0","info":{"title":"Procest","version":"0.0.0"},"paths":{}}` lets Redocusaurus render without failing. The real spec is tracked in issue #442.

### D5: `nl` locale re-enable with escape hatch

ADR-030 documents SSR failures when `i18n/nl/` contains stale metadata without translated markdown. The config will add `'nl'` to `locales` with a comment explaining the escape hatch: if the `npm run build` fails on nl, revert `locales` to `['en']` and cite issue #441. The build verification step in the apply task handles this automatically.

### D6: Em-dash replacement strategy

Em-dashes in the Technical/ files (especially `market-analysis.md`) are prose em-dashes that separate clauses. Replacement: `' — '` (space-em-dash-space) → `', '` (comma-space) or `': '` (colon-space) depending on context. Em-dashes in feature files are mostly in technical bullet lists (e.g., `` `endpoint()` — description ``): replace with `: ` or ` - ` for code-reference bullets. Em-dashes in HTML comments (tutorials) are purely in the `{{TODO: ... — see /journeydoc-add-story}}` template: replace `—` with `-`.

Use Edit tool with targeted replacements per file. Never use sed/awk/python.

## Risks / Trade-offs

- **Sidebar order disruption** → Docusaurus autogenerated sidebar uses alphabetical order by default; folder renames (lower→upper case) may shift the sidebar position of Features/ relative to other top-level items. Mitigation: add `_category_.json` with explicit `position` to each canonical folder to pin the order.

- **nl SSR failure** → Known ADR-030 issue. Mitigation: build verification step tries `npm run build`; if nl SSR breaks, the apply task reverts `locales` to `['en']` with a comment.

- **Internal cross-link breakage** → Files moved to `Technical/` may have relative links pointing to other root-level MDs. Mitigation: scan moved files for relative MD links and update paths as part of the move task.

- **Screenshots in tutorials/** → The PR #437 added screenshots to `tutorials/user/` and `tutorials/admin/`. The `git mv docs/tutorials docs/user-guide` will move the entire subtree including PNG files. Verify screenshot count before and after.



## Tasks

## 1. Folder renames (git mv — preserves history)

- [ ] 1.1 `git mv docs/features docs/Features` — rename features folder to canonical casing (44 feature files + README.md)
- [ ] 1.2 `git mv docs/tutorials docs/user-guide` — rename tutorials folder to canonical name (11 MD files in admin/ + user/ subdirs, plus all screenshots)
- [ ] 1.3 Verify screenshot count in `docs/user-guide/` matches original `docs/tutorials/` (PNG files must all be present after mv)

## 2. Create Technical/ folder and move legacy root MDs

- [ ] 2.1 Create `docs/Technical/` folder with `_category_.json` (label: "Technical", position: 6)
- [ ] 2.2 `git mv docs/ARCHITECTURE.md docs/Technical/architecture.md`
- [ ] 2.3 `git mv docs/DESIGN-REFERENCES.md docs/Technical/design-decisions.md`
- [ ] 2.4 `git mv docs/development.md docs/Technical/development-guide.md`
- [ ] 2.5 `git mv docs/zgw-implementation.md docs/Technical/zgw-spec.md`
- [ ] 2.6 `git mv docs/GOVERNMENT-FEATURES.md docs/Technical/government-compliance.md`
- [ ] 2.7 `git mv docs/FEATURES.md docs/Technical/market-analysis.md`

## 3. Root README rename and index.md creation

- [ ] 3.1 `git mv docs/README.md docs/index.md`
- [ ] 3.2 Add Docusaurus frontmatter to `docs/index.md`: `id: intro`, `title: Introduction`, `sidebar_position: 1`

## 4. Create installation.md

- [ ] 4.1 Create `docs/installation.md` with:
  - `sidebar_position: 2` frontmatter
  - Prerequisites section (Nextcloud 28+, OpenRegister app)
  - App Store installation steps (install from Nextcloud App Store, enable app)
  - Post-install configuration section: register setup (procest register created automatically via repair step), case-type configuration, ZGW API endpoint mapping in Admin Settings
  - Troubleshooting section (register not found: re-run repair step; ZGW 400 errors: verify endpoint URLs)

## 5. Create UseCases/ and Integrations/ stub folders

- [ ] 5.1 Create `docs/UseCases/_category_.json` (label: "Use Cases", position: 4)
- [ ] 5.2 Create `docs/UseCases/index.md` stub (frontmatter: `draft: true`, title: "Use Cases", note: content authoring tracked in issue #440)
- [ ] 5.3 Create `docs/Integrations/_category_.json` (label: "Integrations", position: 5)
- [ ] 5.4 Create `docs/Integrations/index.md` stub (frontmatter: `draft: true`, title: "Integrations", note: content authoring tracked in issue #440)

## 6. _category_.json files for canonical folders

- [ ] 6.1 Create `docs/Features/_category_.json` (label: "Features", position: 3)
- [ ] 6.2 Create `docs/user-guide/_category_.json` (label: "User Guide", position: 2) — only if one does not already exist
- [ ] 6.3 Create `docs/Technical/_category_.json` if not already done in task 2.1

## 7. Add Redocusaurus and API Documentation route

- [ ] 7.1 Add `"redocusaurus": "^2.0.0"` to `docs/package.json` under `dependencies`
- [ ] 7.2 Create `docs/static/oas/` directory and write `docs/static/oas/procest.json` with minimal valid OAS shim: `{"openapi":"3.0.0","info":{"title":"Procest","version":"0.0.0"},"paths":{}}`
- [ ] 7.3 Add Redocusaurus plugin config to `docs/docusaurus.config.js`: plugin `redocusaurus` with `specs: [{spec: 'static/oas/procest.json', route: '/api/'}]`
- [ ] 7.4 Add "API Documentation" navbar item to `docs/docusaurus.config.js`: `{to: '/api/', label: 'API Documentation', position: 'left'}`

## 8. Re-enable nl locale

- [ ] 8.1 In `docs/docusaurus.config.js`, update `i18n.locales` from `['en']` to `['en', 'nl']`
- [ ] 8.2 Add `nl: { label: 'Nederlands' }` to `i18n.localeConfigs`
- [ ] 8.3 Run `npm run build` in `docs/`; if SSR fails with nl locale, revert `locales` to `['en']` and add comment: `/* nl reverted: SSR error with empty i18n/nl/ — re-enable once translation backfill lands (issue #441) */`

## 9. Em-dash sweep (gate: git grep -E '—' docs/ returns 0)

- [ ] 9.1 Fix em-dashes in `docs/Technical/market-analysis.md` (11 hits from FEATURES.md): replace ` — ` with `, ` or `: ` per context; title `# Procest — Feature Analysis` → `# Procest: Feature Analysis`
- [ ] 9.2 Fix em-dashes in `docs/Features/README.md` (table cells and prose): replace ` — ` with `, ` or `: `
- [ ] 9.3 Fix em-dashes in `docs/Features/administration.md` (bullet list items with ` — `): replace ` — ` with `: `
- [ ] 9.4 Fix em-dashes in `docs/Features/zgw-apis.md`: replace ` — ` with `: ` in code-reference bullets
- [ ] 9.5 Fix em-dashes in `docs/Features/bezwaar-beroep-workflow.md`: replace ` — ` with `: `
- [ ] 9.6 Fix em-dashes in `docs/Features/workflow-engine-enhancement.md`: replace ` — ` with `: `
- [ ] 9.7 Fix em-dashes in `docs/Features/vth-workflow-configuration.md`: replace ` — ` with `: `
- [ ] 9.8 Fix em-dashes in `docs/Features/besluitvorming-workflow.md`: replace ` — ` with `: `
- [ ] 9.9 Fix em-dashes in `docs/Features/doorlooptijd-dashboard.md`: replace ` — ` with `: `
- [ ] 9.10 Fix em-dashes in `docs/Features/deelzaak-support.md`: replace ` — ` with `: `
- [ ] 9.11 Fix em-dashes in `docs/Features/app-scaffold.md`: replace ` — ` with `: `
- [ ] 9.12 Fix em-dashes in `docs/Features/gis-integration.md`: replace ` — ` with `: `
- [ ] 9.13 Fix em-dashes in `docs/user-guide/` HTML comments (tutorials): in `<!-- {{TODO: ... — see /journeydoc-add-story}} -->`, replace `—` with `-`
- [ ] 9.14 Verify em-dash gate: run `git grep -E '—' docs/` (excluding node_modules) — must return 0 matches

## 10. Internal link updates in moved files

- [ ] 10.1 Scan all files moved to `docs/Technical/` for relative links pointing to sibling root MDs (e.g., `../ARCHITECTURE.md`) and update to the new paths
- [ ] 10.2 Scan `docs/Features/` files for any cross-links to root MDs and update accordingly
- [ ] 10.3 Verify `docs/index.md` links to `features/` are updated to `Features/` (if any)

## 11. Build verification

- [ ] 11.1 Run `cd docs && npm install --legacy-peer-deps`
- [ ] 11.2 Run `npm run build` (with 10-minute timeout) — must exit 0
- [ ] 11.3 If build fails due to nl SSR error, execute escape hatch from task 8.3 and re-run build
- [ ] 11.4 Confirm build output includes `Features/`, `user-guide/`, `Technical/`, `api/` routes