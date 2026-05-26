# Tasks: docs-product-pages-conformance

This is a `kind: config` change (docs-only). Tasks cover file renames,
new file creation, config edits, and em-dash fixes. No PHP, Vue, or
backend code changes. No OpenRegister schema changes.

---

## T1 — Folder renames (REQ-DPP-001)

- [ ] **T1.1** `git mv docs/features docs/Features` — renames the features
  folder to canonical casing (44 feature files + README.md)
  - files: `docs/Features/` (all contents)
  - acceptance: `git log --follow docs/Features/administration.md` shows
    history from `docs/features/administration.md`

- [ ] **T1.2** `git mv docs/tutorials docs/user-guide` — renames tutorials
  folder to canonical name (11 MD files in admin/ + user/ subdirs, plus
  all PNG screenshots)
  - files: `docs/user-guide/` (all contents including screenshots)
  - acceptance: PNG count under `docs/user-guide/` equals PNG count that
    was under `docs/tutorials/` before the rename

## T2 — Create Technical/ folder and move legacy root MDs (REQ-DPP-001)

- [ ] **T2.1** Create `docs/Technical/_category_.json` with
  `{"label": "Technical", "position": 6}`
  - files: `docs/Technical/_category_.json`

- [ ] **T2.2** `git mv docs/ARCHITECTURE.md docs/Technical/architecture.md`
  - files: `docs/Technical/architecture.md`

- [ ] **T2.3** `git mv docs/DESIGN-REFERENCES.md docs/Technical/design-decisions.md`
  - files: `docs/Technical/design-decisions.md`

- [ ] **T2.4** `git mv docs/development.md docs/Technical/development-guide.md`
  - files: `docs/Technical/development-guide.md`

- [ ] **T2.5** `git mv docs/zgw-implementation.md docs/Technical/zgw-spec.md`
  - files: `docs/Technical/zgw-spec.md`

- [ ] **T2.6** `git mv docs/GOVERNMENT-FEATURES.md docs/Technical/government-compliance.md`
  - files: `docs/Technical/government-compliance.md`

- [ ] **T2.7** `git mv docs/FEATURES.md docs/Technical/market-analysis.md`
  - files: `docs/Technical/market-analysis.md`
  - acceptance: `docs/FEATURES.md` MUST NOT exist after this task

## T3 — Root index document (REQ-DPP-002)

- [ ] **T3.1** `git mv docs/README.md docs/index.md`
  - files: `docs/index.md`

- [ ] **T3.2** Add Docusaurus frontmatter to `docs/index.md`:
  ```yaml
  ---
  id: intro
  title: Introduction
  sidebar_position: 1
  ---
  ```
  Insert before the first line of existing content.
  - files: `docs/index.md`
  - acceptance: `docs/README.md` MUST NOT exist; `docs/index.md` opens
    with the three-field frontmatter block

## T4 — Create installation.md (REQ-DPP-003)

- [ ] **T4.1** Create `docs/installation.md` with:
  - Frontmatter: `sidebar_position: 2`, `title: Installation`
  - Section 1 — Prerequisites: Nextcloud 28+, OpenRegister app enabled
  - Section 2 — App Store installation: install Procest from Nextcloud
    App Store, enable app via Apps admin panel
  - Section 3 — Post-install configuration: procest register created
    automatically via Nextcloud repair step; case-type configuration;
    ZGW API endpoint mapping in Admin Settings
  - Section 4 — Troubleshooting: register not found → re-run Nextcloud
    repair step; ZGW 400 errors → verify endpoint URLs in Admin Settings
  - files: `docs/installation.md`
  - acceptance: all four sections present; ZGW endpoint mapping explicitly
    mentioned in section 3

## T5 — Create UseCases/ and Integrations/ stub folders (REQ-DPP-001)

- [ ] **T5.1** Create `docs/UseCases/_category_.json`:
  `{"label": "Use Cases", "position": 4}`
  - files: `docs/UseCases/_category_.json`

- [ ] **T5.2** Create `docs/UseCases/index.md` stub:
  ```markdown
  ---
  draft: true
  title: Use Cases
  ---
  # Use Cases

  Content authoring is tracked in issue #440.
  ```
  - files: `docs/UseCases/index.md`

- [ ] **T5.3** Create `docs/Integrations/_category_.json`:
  `{"label": "Integrations", "position": 5}`
  - files: `docs/Integrations/_category_.json`

- [ ] **T5.4** Create `docs/Integrations/index.md` stub:
  ```markdown
  ---
  draft: true
  title: Integrations
  ---
  # Integrations

  Content authoring is tracked in issue #440.
  ```
  - files: `docs/Integrations/index.md`

## T6 — _category_.json for renamed canonical folders (REQ-DPP-001)

- [ ] **T6.1** Create `docs/Features/_category_.json`:
  `{"label": "Features", "position": 3}`
  - files: `docs/Features/_category_.json`
  - acceptance: file must not already exist from T1.1 (git mv does not
    create it)

- [ ] **T6.2** Create `docs/user-guide/_category_.json`:
  `{"label": "User Guide", "position": 2}` — only if one does not
  already exist in the renamed folder
  - files: `docs/user-guide/_category_.json`

## T7 — Redocusaurus and API Documentation route (REQ-DPP-004)

- [ ] **T7.1** Add `"redocusaurus": "^2.0.0"` to the `dependencies`
  object in `docs/package.json`
  - files: `docs/package.json`

- [ ] **T7.2** Create `docs/static/oas/procest.json`:
  `{"openapi":"3.0.0","info":{"title":"Procest","version":"0.0.0"},"paths":{}}`
  - files: `docs/static/oas/procest.json`
  - acceptance: valid JSON, parseable without errors

- [ ] **T7.3** Add Redocusaurus plugin config to `docs/docusaurus.config.js`:
  ```js
  ['redocusaurus', { specs: [{ spec: 'static/oas/procest.json', route: '/api/' }] }]
  ```
  - files: `docs/docusaurus.config.js`

- [ ] **T7.4** Add "API Documentation" navbar item to `docs/docusaurus.config.js`:
  ```js
  { to: '/api/', label: 'API Documentation', position: 'left' }
  ```
  - files: `docs/docusaurus.config.js`

## T8 — Re-enable nl locale (REQ-DPP-005)

- [ ] **T8.1** Update `i18n.locales` in `docs/docusaurus.config.js` from
  `['en']` to `['en', 'nl']` and add `nl: { label: 'Nederlands' }` to
  `i18n.localeConfigs`
  - files: `docs/docusaurus.config.js`

- [ ] **T8.2** Run `npm run build` in `docs/`; if SSR fails due to empty
  `i18n/nl/`, revert `locales` to `['en']` and add escape-hatch comment
  (see REQ-DPP-005); re-run build and confirm exit 0
  - acceptance: build exits 0 in either the nl-enabled or nl-reverted state

## T9 — Em-dash sweep (REQ-DPP-006)

- [ ] **T9.1** Fix em-dashes in `docs/Technical/market-analysis.md`
  (11 hits including title `# Procest — Feature Analysis` →
  `# Procest: Feature Analysis`); replace ` — ` with `, ` or `: ` per context
  - files: `docs/Technical/market-analysis.md`

- [ ] **T9.2** Fix em-dashes in `docs/Features/README.md` (table cells
  and prose): replace ` — ` with `, ` or `: `
  - files: `docs/Features/README.md`

- [ ] **T9.3** Fix em-dashes in `docs/Features/administration.md`
  (bullet list items): replace ` — ` with `: `
  - files: `docs/Features/administration.md`

- [ ] **T9.4** Fix em-dashes in `docs/Features/zgw-apis.md`
  (code-reference bullets): replace ` — ` with `: `
  - files: `docs/Features/zgw-apis.md`

- [ ] **T9.5** Fix em-dashes in `docs/Features/bezwaar-beroep-workflow.md`:
  replace ` — ` with `: `
  - files: `docs/Features/bezwaar-beroep-workflow.md`

- [ ] **T9.6** Fix em-dashes in `docs/Features/workflow-engine-enhancement.md`:
  replace ` — ` with `: `
  - files: `docs/Features/workflow-engine-enhancement.md`

- [ ] **T9.7** Fix em-dashes in `docs/Features/vth-workflow-configuration.md`:
  replace ` — ` with `: `
  - files: `docs/Features/vth-workflow-configuration.md`

- [ ] **T9.8** Fix em-dashes in `docs/Features/besluitvorming-workflow.md`:
  replace ` — ` with `: `
  - files: `docs/Features/besluitvorming-workflow.md`

- [ ] **T9.9** Fix em-dashes in `docs/Features/doorlooptijd-dashboard.md`:
  replace ` — ` with `: `
  - files: `docs/Features/doorlooptijd-dashboard.md`

- [ ] **T9.10** Fix em-dashes in `docs/Features/deelzaak-support.md`:
  replace ` — ` with `: `
  - files: `docs/Features/deelzaak-support.md`

- [ ] **T9.11** Fix em-dashes in `docs/Features/app-scaffold.md`:
  replace ` — ` with `: `
  - files: `docs/Features/app-scaffold.md`

- [ ] **T9.12** Fix em-dashes in `docs/Features/gis-integration.md`:
  replace ` — ` with `: `
  - files: `docs/Features/gis-integration.md`

- [ ] **T9.13** Fix em-dashes in `docs/user-guide/` HTML comments
  (pattern `{{TODO: ... — see /journeydoc-add-story}}`):
  replace `—` with `-`
  - files: all MD files under `docs/user-guide/`

- [ ] **T9.14** Verify em-dash gate: run `git grep -E '—' docs/`
  (excluding node_modules) — MUST return 0 matches
  - acceptance: command exits with code 1 (no matches), zero output lines

## T10 — Internal link updates (REQ-DPP-001)

- [ ] **T10.1** Scan all files moved to `docs/Technical/` for relative
  links pointing to sibling root MDs (e.g., `../ARCHITECTURE.md`) and
  update to new paths (e.g., `./architecture.md`)
  - files: all files under `docs/Technical/`

- [ ] **T10.2** Scan `docs/Features/` files for any cross-links to root
  MDs and update to new Technical/ paths
  - files: all files under `docs/Features/`

- [ ] **T10.3** Verify `docs/index.md` — if any links reference `features/`
  (lowercase), update to `Features/`
  - files: `docs/index.md`

## T11 — Build verification (REQ-DPP-007)

- [ ] **T11.1** Run `cd docs && npm install --legacy-peer-deps` (installs
  redocusaurus and all dependencies)
  - acceptance: exits 0

- [ ] **T11.2** Run `npm run build` with a 10-minute timeout — MUST exit 0
  - acceptance: exit code 0; build output directory exists

- [ ] **T11.3** If build fails due to nl SSR error, execute escape hatch
  from T8.2 and re-run build
  - acceptance: final build exits 0

- [ ] **T11.4** Confirm build output contains the four canonical routes:
  `Features/`, `user-guide/`, `Technical/`, `api/`
  - acceptance: all four paths are present in the build output directory
