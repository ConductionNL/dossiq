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
