English that a Dutch reader met on the review instance (10 Oct, finding F4).

- **Case tabs and panels.** nextcloud-vue's CnTabsWidget draws `tabs[].label` as written and CaseSectionsWidget draws `sections[].label` as written, so Overview, Documents, Core case data and the rest stayed English although `nl.json` had them. `translateBannerCopy()` already translates captions and banner copy before the manifest reaches the renderer; it now also translates `tabs[].label`, `sections[].label`, `moreLabel` and `ariaLabel`.
- **Dashboard captions.** "{tasksDueToday} due today" and "met their deadline" had no Dutch. Added as DqDashboard words them: "vandaag af", "haalden hun termijn". Also "Case details" (Zaakgegevens) for the tab strip's label.
- **Failed case change.** The backend now answers `{"error":"The case could not be changed.","code":"change_failed"}`; `refusalMessage()` maps the code to its translated sentence ("De zaak kon niet worden gewijzigd.").

Not here: the Termijn tile's "58 days left" comes from nextcloud-vue's CnCountdownWidget, which translates "{count} days left" against the library's own catalogue, and that has no Dutch entry. That is a library catalogue fix.

OpenSpec: `dutch-copy-on-the-case-page` (REQ-FLC-010, REQ-FLC-011), `openspec validate --strict` clean.

**Verified live** on :8098 with this branch built and its catalogue copied in: case tabs read Overzicht, Documenten, Contact, Taken, Geschiedenis, ...; panels Kerngegevens van de zaak, Woo-publicatie, ...; KPI tiles "0 vandaag af" and "haalden hun termijn". Screenshots `dqf-f4-case-tabs-after.png`, `dqf-f4-dashboard-after.png`.

**Local verification** (after merging origin/development 421a8c7a0, head ab73dde6d): `flock ~/lanes/heavy.lock npx vitest run --maxWorkers=2` exit 0 (278 files, 3210 tests); `npm run lint` exit 0; `npm run format` exit 0; `npm run check:manifest` exit 0; `npm run test:l10n` exit 0; `npm run check:l10n-js` exit 0; `npm run check:schema-l10n` exit 0; `npm run check:debt-ratchet` exit 0; `openspec validate `dutch-copy-on-the-case-page` --strict` exit 0. No PHP changed.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
