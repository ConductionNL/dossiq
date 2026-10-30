# Tasks: advice-and-consultation-on-the-case

- [ ] 1 `appinfo/routes.php`: `POST/GET /api/cases/{id}/advice-requests` on `AdviceController::createForCase`/`getForCase`; the `/api/vth/cases/{id}/advice-requests` paths forward for one release. Update `tests/Unit/Controller/AdviceControllerTest.php`.
- [ ] 2 `src/dialogs/AdviceRequestDialog.vue` (adviser from the advisory bodies registry, question, deadline) and `src/dialogs/ConsultationStartDialog.vue` (consulted department, question, deadline) posting to the advice and consultation routes.
- [ ] 3 `src/manifest.json` CaseDetail `headerActions`: `advice-request` and `consultation-start` (`open-modal`).
- [ ] 4 `src/manifest.json` CaseDetail widget `advice-and-consultations`: an object table over both relations, columns kind, adviser, state, deadline, answer; row actions remind (`/api/advice/{id}/remind`) and withdraw (transition).
- [ ] 5 The queue rows that link to the missing advice and consultation tabs open the case with the widget in view instead.
- [ ] 6 `tests/e2e/advice-on-the-case.spec.ts`: the three scenarios of REQ-ADV-CASE-01.
- [ ] 7 Live pass against DqZaakBesluiten, DqZaak and DqUwWachtrij.
- [ ] 8 `openspec/parity/capabilities.json` row 3.35: built, evidence the actions and the widget.
