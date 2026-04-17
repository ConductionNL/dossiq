<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: Beroep Escalation

## Implementation Tasks

### Seed Data (REQ-BER-001, REQ-BER-002)

- [ ] **T01**: Add Beroep `caseType` seed object to `lib/Settings/procest_register.json` with slug `beroep`, `processingDeadline: "P26W"`, `suspensionAllowed: true`, `extensionAllowed: false`, `isDraft: false`, `origin: "external"`. Verify idempotency: re-import MUST NOT create duplicates (match by slug).

- [ ] **T02**: Add 9 `statusType` seed objects to `procest_register.json` for the Beroep case type: Beroep ontvangen (order 1), Verweerschrift in voorbereiding (order 2), Verweerschrift ingediend (order 3), Zitting gepland (order 4), Zitting afgerond (order 5), Uitspraak ontvangen (order 6), Afgehandeld (order 7, isFinal), Ingetrokken (order 90, isFinal), Schikking (order 91, isFinal). Cross-reference `caseType` by slug.

- [ ] **T03**: Add `roleType` seed objects to `procest_register.json` for Beroep: Behandelaar, Appellant, Rechtbank-contactpersoon — all referencing `caseType: "@slug:beroep"`.

- [ ] **T04**: Add `resultType` seed objects for four ruling outcomes: `beroep-gegrond`, `beroep-ongegrond`, `beroep-deels-gegrond`, `beroep-niet-ontvankelijk` — all with `archivalPeriod: "P10Y"` and `archivalAction: "bewaren"`.

- [ ] **T05**: Add `documentType` seed objects: Beroepschrift, Verweerschrift, Uitspraak rechtbank — all referencing `caseType: "@slug:beroep"`, `isDraft: false`, `allowedMimeTypes: ["application/pdf"]`.

- [ ] **T06**: Add `propertyDefinition` seed object `voorzieningRequested` (`propertyType: "boolean"`, `defaultValue: "false"`) for Beroep case type.

- [ ] **T07**: Add 3 example `case` seed objects (BR-2026-0001, BR-2026-0002, BR-2026-0003) to `procest_register.json` with realistic Dutch values; BR-2026-0003 MUST have `priority: "urgent"` to illustrate the voorlopige voorziening scenario.

### Backend: Escalation (REQ-BER-003, REQ-BER-004, REQ-BER-005)

- [ ] **T08**: Create `lib/Service/BeroepEscalationService.php` with method `escalateToBeroep(string $bezwaarCaseId, array $overrides): array`. Logic: validate bezwaar case exists and is in status "Beslissing op bezwaar" or "Afgehandeld"; create new `case` object via OpenRegister with `caseType = beroep`, `parentCase = bezwaarCaseId`, `status = beroep-ontvangen`; copy initiator role from bezwaar as Appellant role on beroep; set `voorzieningRequested` caseProperty if provided. Return created beroep case. Add `@spec` docblock: `openspec/changes/beroep-escalation/tasks.md#T08`.

- [ ] **T09**: Create `lib/Controller/BeroepEscalationController.php` with endpoint `POST /api/cases/{id}/escalate-to-beroep`. Validate the source case exists and has the correct status; delegate to `BeroepEscalationService`; return `201 Created` with the new beroep case payload. Add `@spec` docblock: `openspec/changes/beroep-escalation/tasks.md#T09`.

- [ ] **T10**: Add escalation route to `appinfo/routes.php`: `POST /api/cases/{id}/escalate-to-beroep` → `BeroepEscalationController@escalate`. Ensure the specific route is registered BEFORE any wildcard `{slug}` route.

### Backend: Uitspraak Recording (REQ-BER-006)

- [ ] **T11**: Create `lib/Service/UitspraakService.php` with method `recordUitspraak(string $beroepCaseId, string $outcome, bool $createFollowUpTask): array`. Logic: validate outcome is one of `beroep_gegrond | beroep_ongegrond | deels_gegrond | niet_ontvankelijk`; set case `result` to matching resultType; transition case status to "Uitspraak ontvangen"; if `createFollowUpTask` is true and outcome is gegrond/deels_gegrond, create a `task` object with title "Nieuw besluit nemen naar aanleiding van uitspraak rechtbank", status "available", priority "high". Add `@spec` docblock: `openspec/changes/beroep-escalation/tasks.md#T11`.

- [ ] **T12**: Create `lib/Controller/UitspraakController.php` with endpoint `POST /api/cases/{id}/uitspraak`. Accept body: `{ "outcome": "beroep_gegrond|...", "createFollowUpTask": true|false }`. Delegate to `UitspraakService`. Return `200 OK` with updated case. Add `@spec` docblock: `openspec/changes/beroep-escalation/tasks.md#T12`.

- [ ] **T13**: Add uitspraak route to `appinfo/routes.php`: `POST /api/cases/{id}/uitspraak` → `UitspraakController@record`.

### Frontend API Service

- [ ] **T14**: Create `src/services/beroepEscalatieApi.js` with two functions:
  - `escalateToBeroep(caseId, payload)` — `POST /api/cases/{caseId}/escalate-to-beroep`
  - `recordUitspraak(caseId, payload)` — `POST /api/cases/{caseId}/uitspraak`
  Both wrapped with try/catch and axios from `@nextcloud/axios`.

### Frontend: Escalation Dialog (REQ-BER-003, REQ-BER-004, REQ-BER-005)

- [ ] **T15**: Create `src/views/cases/components/BeroepEscalatieDialog.vue`. Props: `bezwaarCase` (object). Features: pre-fill title ("Beroep: [bezwaar title]"), description referencing bezwaar grounds, appellant name from bezwaar initiator role; boolean toggle for `voorzieningRequested` with informational note about urgency; all fields editable; submit calls `beroepEscalatieApi.escalateToBeroep()`; on success, navigate to new beroep case; on error, show error message via NcDialog.

- [ ] **T16**: Modify `src/views/cases/CaseDetail.vue`:
  - Add "Escaleren naar beroep" action button in the case actions section, visible ONLY when `case.caseType` is "Bezwaar" AND `case.status` is in ["Beslissing op bezwaar", "Afgehandeld"]
  - Import and render `BeroepEscalatieDialog` conditionally
  - After successful escalation, add activity entry and show link to created beroep case in activity timeline

### Frontend: Verweerschrift Status Transition (REQ-BER-006)

- [ ] **T17**: In `src/views/cases/CaseDetail.vue` (or a dedicated document upload handler), after a document is uploaded with `documentType = "Verweerschrift"` and the case is a Beroep case in status "Verweerschrift in voorbereiding": show NcDialog confirmation prompt "Verweerschrift indienen? Dit zet de status op 'Verweerschrift ingediend'."; on confirm, update the case status via the existing status update API.

### Frontend: Uitspraak Dialog (REQ-BER-006)

- [ ] **T18**: Create `src/views/cases/components/UitspraakDialog.vue`. Features: dropdown with 4 outcome options (beroep_gegrond, beroep_ongegrond, deels_gegrond, niet_ontvankelijk) with Dutch labels; conditional "Maak taak aan voor nieuw besluit" checkbox shown when outcome is gegrond or deels_gegrond; submit calls `beroepEscalatieApi.recordUitspraak()`; on success, update local case state, close dialog.

- [ ] **T19**: Modify `src/views/cases/CaseDetail.vue`:
  - Add "Uitspraak registreren" action button, visible ONLY when case type is "Beroep" AND status is "Zitting afgerond"
  - Import and render `UitspraakDialog` conditionally

### Frontend: Hoger Beroep Banner (REQ-BER-007)

- [ ] **T20**: Create `src/views/cases/components/HogerBeroepBanner.vue`. Features: shows informational text about hoger beroep at ABRvS/CRvB with 6-week deadline; dismissable via close button (store dismiss state in component data, NOT in OpenRegister); MUST NOT include "Escaleren naar hoger beroep" button.

- [ ] **T21**: Modify `src/views/cases/CaseDetail.vue`:
  - Import and render `HogerBeroepBanner` conditionally ONLY when case type is "Beroep" AND status is in ["Uitspraak ontvangen", "Afgehandeld"]

## Verification Tasks

- [ ] **V01**: After repair step, `caseType` "Beroep" exists with `processingDeadline: "P26W"`, `isDraft: false`, `suspensionAllowed: true`
- [ ] **V02**: 9 `statusType` records exist for Beroep; orders 7, 90, 91 have `isFinal: true`
- [ ] **V03**: "Escaleren naar beroep" action is visible on a bezwaar case with status "Beslissing op bezwaar" and invisible on earlier statuses
- [ ] **V04**: Escalation creates a beroep case with `parentCase` set to the source bezwaar case UUID and status "Beroep ontvangen"
- [ ] **V05**: Created beroep case pre-fills appellant from bezwaar initiator role
- [ ] **V06**: `voorzieningRequested = true` sets beroep case priority to "urgent" and renders urgency badge
- [ ] **V07**: Uploading a Verweerschrift document triggers status transition confirmation and transitions status to "Verweerschrift ingediend" on confirmation
- [ ] **V08**: Recording a ruling transitions status to "Uitspraak ontvangen" and sets case result
- [ ] **V09**: Recording beroep_gegrond with createFollowUpTask = true creates a task "Nieuw besluit nemen naar aanleiding van uitspraak rechtbank"
- [ ] **V10**: HogerBeroepBanner displays on beroep case in status "Uitspraak ontvangen" with correct ABRvS/CRvB text; banner does NOT appear before uitspraak is recorded
- [ ] **V11**: Re-running repair step does NOT create duplicate caseType, statusType, or other seed objects
