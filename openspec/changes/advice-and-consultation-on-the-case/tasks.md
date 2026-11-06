# Tasks: advice-and-consultation-on-the-case

- [x] 1 `appinfo/routes.php`: `POST/GET /api/cases/{id}/advice-requests` on `AdviceController::createForCase`/`getForCase`; the `/api/vth/cases/{id}/advice-requests` paths answer for one release through `createForCaseLegacyPath`/`getForCaseLegacyPath` (own names, because Routes::standard() rejects duplicates). `tests/Unit/Controller/AdviceControllerContractTest.php` covers both.
- [x] 2 Superseded by the board (decision 130): DqZaakBesluiten draws one Advies vragen in the advice card, internal or external, and no separate consultation dialog. Ask for advice in the card's title row (`src/menu-layout.simple.json`, `case-advice-requests` headerActions, an `open-form` on `adviesAanvraag` with subject, advisor, type intern/extern, deadline, questions) is that action. Spec amended.
- [x] 3 Superseded with task 2: no header actions on the case; the action sits in the card as the board draws it.
- [x] 4 The card `case-advice-requests` (record-list on the Decisions tab of the default simple layout): advisor initials, subject and advisor, asked and due or received, the status pill, Remind on an open request (`/api/advice/{id}/remind`). Withdraw is not drawn and `adviesAanvraag` has no withdrawn state, so it is left out.
- [x] 5 Queue rows: an advice request opens its case on `#case-decisions-panel` (the tabs widget opens the tab named by the route hash) and leaves the queue once received or expired; a consultation opens its case, without the `tab=consultations` query no tab answered. `tests/Unit/Service/Queue/AdviceQueueRowsTest.php`.
- [x] 6 `tests/e2e/advice-on-the-case.spec.ts`: the three scenarios of REQ-ADV-CASE-01 (written, not yet run against a live instance).
- [ ] 7 Live pass against DqZaakBesluiten, DqZaak and DqUwWachtrij.
- [x] 8 `openspec/parity/capabilities.json` row 3.35: built, evidence the card, the routes and the queue rows. The dossiq rating stays with the parity corpus.
