# Tasks: complaints-are-a-case-type

## 1. Case type

- [x] 1.1 Verify Awb 9:11 (and 9:14 for the committee); cite the article text on the case type. The register's `klacht-behandeling` description now cites 9:11 lid 1 (six weeks, ten with a klachtadviescommissie), lid 2 (one verdaging of at most four weeks) and lid 3 (further only with the complainant's written consent). wetten.overheid.nl did not render chapter 9 to the fetch tool; the text was read from published quotations of 9:11. A lane with a browser re-reads it on wetten.overheid.nl.
- [x] 1.2 The case type ships in the register, not as a package: `klacht-behandeling` is already seeded by `lib/Settings/dossiq_register.json` and the KCC quick action, the intake action `createKlacht` and the Complaints view depend on it being there on every instance. A package would only adopt it (decision 190), and an adopted case type takes none of the package's case-type fields, so `sessions[]` and `recordImports` would never reach it. Completed in the register: statuses as the board's steps (Ontvangen, Bevestigd, Hoorgesprek, Oordeel, Antwoordbrief, Afgehandeld), roles (Klager, Betrokken medewerker, Klachtencoördinator), results (gegrond, deels gegrond, ongegrond, niet-ontvankelijk, ingetrokken). Term stays `P42D` with one `P28D` extension (`td-klacht-behandeling`).
- [x] 1.3 The committee variant (ten weeks, Awb 9:11 lid 1 with afdeling 9.1.3), decision 273a: generic case type field `statutoryTermDefinition` picks a second statutory definition, read by `TermResolution` (after organisation, service and priority, before the default). Shipped `td-klacht-behandeling-commissie` (70 days, one 28-day extension, `pickedByCaseType`). `klacht-behandeling` ships with no pick (REQ-CMP-CT-03).
- [x] 1.4 `tests/integration/klacht-behandeling.postman_collection.json`: start a `klacht-behandeling` case with receipt date 1 March and read its term (12 April). The case is started through the OpenRegister objects API with `receivedAt` Monday 2021-03-01 and its statutory term read through `GET /api/cases/{id}/terms`; teardown deletes it. Not yet run against a live instance: it runs locally like the main suite (README).

## 2. Surfaces

- [x] 2.1 Saved view of the Cases list: `complaints` in `lib/Settings/case-type-views.json` (case type identifier `klacht-behandeling`) already exists. No menu entry: there are no case-type-specific menu entries (Ruben, 20 Oct, `src/menu-layout.json`).
- [x] 2.2 Hearing: the case type declares the generic session `hoorgesprek` in `sessions[]` (invitees Klager, Betrokken medewerker, Klachtencoördinator; waivable, Awb 9:10 lid 2; minutes required). No complaint code.
- [x] 2.3 CaseDetail offers a declared session as an action. Waits on `case-session-with-parties-and-minutes`: `SessionService` has no route or case-page action yet.
  - `GET`/`POST /api/cases/{caseId}/sessions` (`CaseSessionController` over `SessionServiceInterface`): the declared sessions and the ones the case holds, and plan one, chaired by the caller; the floor and invitee-role rules answer 400 in their own words. CaseDetail header action `session-schedule` ("Plan a session", group Case in the simple profile) opens `src/dialogs/SessionScheduleDialog.vue` on any case type whose `sessions` is not empty, so the complaint's `hoorgesprek` is planned from the case page with no complaint code. Tests: `tests/Unit/Controller/CaseSessionControllerTest.php`, `tests/vitest/sessionScheduleDialog.spec.js`. Waiving, attendance and minutes from the case page are not built yet (the service has them).

## 3. Carry-over and retirement

- [x] 3.1 Carry-over through the generic record import, no new repair class: `klacht-behandeling.recordImports` (`complaint` to case, every complaint status mapped, `runOnUpgrade`), run by `RunUpgradeRecordImports`, deduplicated on `externalReference`. `tests/Unit/Settings/ComplaintCaseTypeTest.php` asserts the declaration and maps a stored complaint; idempotency is `CaseRecordImportTest`'s.
- [ ] 3.2 One release later: remove `lib/Service/ComplaintService.php` and the five complaint controllers (`ComplaintController`, `ComplaintAnalyticsController`, `ComplaintCategoryController`, `ComplaintDispositionController`, `ComplaintHearingController`), their routes in `appinfo/routes.php` and their tests; lower the gate 119 baseline.

## 4. Checks

- [ ] 4.1 `tests/e2e/complaint-case-type.spec.ts`: both scenarios of REQ-CMP-CT-01 (the second after 2.3).
  - 25 Nov 2026 live: the first scenario is green (after the spec sends the request token on the terms read); the second stays skipped until 2.3.
  - 26 Nov 2026 live (NC 34 + MariaDB + OpenRegister main): the second scenario is written and green (hearing planned from the case page with both parties, listed by `GET /api/cases/{id}/sessions`, and on the History tab as a `sessie` entry), after two app fixes: `session.chairperson` holds the chairing user's id (it declared a role uuid and refused every plan) and a planned session is recorded on the case timeline. The first scenario's term assertions pass; its last step is red: `/cases?savedView=default:complaints` opened on All cases without the complaint filter, so the new complaint was not on screen. Not triaged further.
- [ ] 4.2 Live pass against board DqKlachten.
  - 25 Nov 2026 live, complaint 2026-4874 (Zuiddrecht set) against the board. Matches: stepper (Ontvangen, Bevestigd, Hoorgesprek, Oordeel, Antwoordbrief, Afgehandeld), Gegevens card (Klager, Onderwerp, Gaat over, Ontvangen, Afdeling, Categorie), Hoorgesprek card (Wanneer, Waar, Verslag, Soort, Aanwezig). Differs:
    - the what-now card reads "Stap 2: in behandeling ... Daarna: naar stap 3, besluit" while the stepper stands on Hoorgesprek (step 3); the board reads "Stap 3: hoorgesprek ... Daarna: naar stap 4, oordeel" with the complaint's own checklist;
    - header: board Hoorgesprek plannen, Bewerken, Oordeel vastleggen (primary); live Bericht, Document, Contact vastleggen, Bewerken, Meer;
    - the Hoorgesprek card lacks Verslag vastleggen and Klager ziet af van horen and the invitation note;
    - side column: board Termijn (Nog 23 dagen; Wettelijk 6 weken, Verdaging, Ontvangstbevestiging rows) and Wie (Klager, Behandelaar, Klachtencoördinator); live a generic Termijn card (18 dagen) and Aanvrager "Nog geen aanvrager";
    - breadcrumb: board Klachten / KL-2026-0041, live Alle zaken / 2026-4874; case-type pill Klacht behandeling vs Klacht (Awb hoofdstuk 9).
- [ ] 4.3 `openspec/parity/capabilities.json` row 2.53: built, evidence the case type and the view.
