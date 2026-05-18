## 1. Folder renames + Technical folder (git mv preserves history)

- [ ] 1.1 `git mv docs/features docs/Features` (44 feature files + README.md) and `git mv docs/tutorials docs/user-guide` (admin/ + user/ subdirs + all screenshots); verify PNG counts match post-mv.
- [ ] 1.2 Create `docs/Technical/` with `_category_.json` (label "Technical", position 6), then `git mv` legacy root MDs in: `ARCHITECTURE.md` → `Technical/architecture.md`, `DESIGN-REFERENCES.md` → `Technical/design-decisions.md`, `development.md` → `Technical/development-guide.md`, `zgw-implementation.md` → `Technical/zgw-spec.md`, `GOVERNMENT-FEATURES.md` → `Technical/government-compliance.md`, `FEATURES.md` → `Technical/market-analysis.md`.

## 2. Root index + installation + UseCases/Integrations stubs

- [ ] 2.1 `git mv docs/README.md docs/index.md` and add frontmatter (`id: intro`, `title: Introduction`, `sidebar_position: 1`).
- [ ] 2.2 Create `docs/installation.md` (`sidebar_position: 2`) with Prerequisites (Nextcloud 28+, OpenRegister), App Store install + enable, post-install configuration (procest register auto-created via repair step, case-type config, ZGW endpoint mapping in Admin Settings), and Troubleshooting (register-not-found → re-run repair step, ZGW 400 → verify endpoint URLs).
- [ ] 2.3 Create `docs/UseCases/_category_.json` (label "Use Cases", position 4) + `docs/UseCases/index.md` stub (`draft: true`, note tracked in #440), and `docs/Integrations/_category_.json` (label "Integrations", position 5) + `docs/Integrations/index.md` stub (`draft: true`, #440).

## 3. _category_.json for canonical folders

- [ ] 3.1 Create `docs/Features/_category_.json` (label "Features", position 3) and `docs/user-guide/_category_.json` (label "User Guide", position 2) if not already present.

## 4. Redocusaurus + API route

- [ ] 4.1 Add `"redocusaurus": "^2.0.0"` to `docs/package.json` dependencies, create `docs/static/oas/procest.json` with minimal valid OAS shim (`{"openapi":"3.0.0","info":{"title":"Procest","version":"0.0.0"},"paths":{}}`), wire Redocusaurus plugin (`specs: [{spec: 'static/oas/procest.json', route: '/api/'}]`) and add `{to: '/api/', label: 'API Documentation', position: 'left'}` navbar item in `docs/docusaurus.config.js`.

## 5. Re-enable nl locale (with escape hatch)

- [ ] 5.1 In `docs/docusaurus.config.js`, update `i18n.locales` to `['en', 'nl']` and add `nl: { label: 'Nederlands' }` to `localeConfigs`. If `npm run build` fails with SSR error on empty `i18n/nl/`, revert `locales` to `['en']` with comment `/* nl reverted: SSR error with empty i18n/nl/ — re-enable once translation backfill lands (issue #441) */`.

## 6. Em-dash sweep (gate: `git grep -E '—' docs/` returns 0)

- [ ] 6.1 Replace ` — ` with `, ` / `: ` (per context) across the em-dash hot spots in `docs/Technical/market-analysis.md` (11 hits incl. title), `docs/Features/README.md`, and per-feature pages: `administration.md`, `zgw-apis.md`, `bezwaar-beroep-workflow.md`, `workflow-engine-enhancement.md`, `vth-workflow-configuration.md`, `besluitvorming-workflow.md`, `doorlooptijd-dashboard.md`, `deelzaak-support.md`, `app-scaffold.md`, `gis-integration.md`.
- [ ] 6.2 Replace `—` with `-` inside `docs/user-guide/` HTML `<!-- {{TODO: ... }} -->` comments, then run `git grep -E '—' docs/` (excluding node_modules) and confirm 0 matches.

## 7. Internal link updates

- [ ] 7.1 Scan files moved into `docs/Technical/` for relative links to sibling root MDs (e.g. `../ARCHITECTURE.md`) and update to new paths. Scan `docs/Features/` for cross-links to root MDs, update. Verify `docs/index.md` references to `features/` are updated to `Features/`.

## 8. Build verification

- [ ] 8.1 `cd docs && npm install --legacy-peer-deps && npm run build` (10-min timeout) must exit 0; apply escape hatch from 5.1 if nl SSR fails, then re-run. Confirm output includes `Features/`, `user-guide/`, `Technical/`, `api/` routes.
