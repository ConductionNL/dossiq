# Tasks: move-portals-to-portaliq

Refs: Conduction/procest#162 · hydra ADR-046. kind: code.

- [x] **T1**: Extend `OCA\Dossiq\Portal\PortalContributionProvider` to three
  audiences — `getAudiences() = ['supplier','citizen','inspector']`,
  `getAudience() = 'supplier'` (v1 fallback), `getContribution()` branching.
  Keep the supplier audience byte-for-byte. Add citizen (mijnZaken/berichten/
  verzoeken + createKlacht) and inspector (inspectieRapporten/checklistRuns)
  audiences, each fields-projected and returning null when not served.

- [x] **T2**: Add three additive OpenRegister scope properties in
  `lib/Settings/dossiq_register.json`: `case.portaalSubject`,
  `inspectieRapport.assignedInspectorRef`,
  `inspectionChecklistRun.assignedInspectorRef` (internal `inspector` untouched).

- [x] **T3**: Retire the in-app nav — remove `PortaalGroup` + its relocations;
  add `LeverancierDashboard`, `MijnZaken`, `MijnNotificaties`, `Inspecties` to
  `menu-layout.json` `removals`.

- [x] **T4**: Delete the in-app portal Vue views (`src/views/leverancier/*`,
  `src/views/portaal/*`), their `registry.js` + `customComponents.js` entries,
  the orphaned `src/services/leverancierApi.js` + `src/utils/portaalForms.js`,
  and the manifest fragments `60-leverancier.json`, `50-zaakportaal.json`,
  `70-mobiel-inspectie.json` (routes are manifest-derived → removed with them).

- [x] **T5**: Delete the e2e/vitest specs that drove the retired views so the
  suite stays green (`leverancier-zaakportaal`, `zaakportaal-mijngemeente`,
  `spec-coverage/portaal-forms`, `spec-coverage/mobiel-inspectie-offline`,
  `vitest/portaalForms`). Keep every backend PHPUnit suite.

- [x] **T6**: Add provider PHPUnit unit tests — assert the 3 audiences + null for
  others, and register-drift-pin every scopeField/projected-field against
  `dossiq_register.json`.

- [ ] **T7**: (deferred) Re-add the citizen bezwaar / message-reply and inspector
  run-submit creates once Portaliq validates create-body cross-refs
  (portaliq#16); raise `minTrust` to `substantial` when the DigiD/eHerkenning
  broker lands.
