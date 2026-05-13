---
status: draft
---
# parafering-actions Specification

## Purpose

Implement actor-facing parafering action recording and e-signature workflow within Procest. Actors (adviseurs, parafeerders, accorderende partijen) can record structured decisions on their assigned voorstel step. Delegate/mandaat actions are supported. A chronological action history timeline is embedded in the voorstel detail view. Digital PDF signature annotations are applied when `accordering` steps are completed, providing an auditable approval trace on the voorstel document.

## Context

The `parafeerroute-engine` change established the routing engine, admin configuration UI, and step activation (notifications + tasks). Actors receive Nextcloud tasks and notifications when their step is activated, but currently have no UI to record their decision. The `parafeeractie` entity (ADR-000) captures immutable action records — this change implements the service, controller, and frontend components that create and retrieve these records. The `onBehalfOf` and `mandate` fields in `parafeeractie` enable delegate workflows without custom RBAC. PDF signature annotation fulfils the contract lifecycle management and e-signature demand from the market (demand score 1901 + 921 + 258 across three related features).

## ADDED Requirements
---

### Requirement: REQ-PAA-001 — Actor Action Taking

The system SHALL provide an actor-facing action dialog for parafering steps. The dialog SHALL adapt its fields to the step type (`advies`, `parafering`, `accordering`). Only the actor assigned to the current step (or a valid delegate) SHALL be permitted to submit an action.

**Feature tier**: V1

#### Scenario: Advies step action

- **GIVEN** actor J. de Vries is assigned to step 1 (type: `advies`) of a voorstel currently at `currentStep` = 1
- **WHEN** J. de Vries opens the voorstel detail view and clicks "Actie nemen"
- **THEN** the system SHALL display a dialog with: step label "Stap 1 — Advies", an advice textarea (verplicht veld), and an optional comment field
- **AND** on submit, the system SHALL create a `parafeeractie` object: `step` = 1, `actor` = J. de Vries's UID, `action` = `advised`, `advice` = the entered text
- **AND** `ParafeerRouteService::completeStep` SHALL be called to advance `voorstel.currentStep` to 2 and activate the step 2 actor

#### Scenario: Parafering step action

- **GIVEN** actor M. Bakker is the assigned actor for step 2 (type: `parafering`) and `currentStep` = 2
- **WHEN** M. Bakker opens the action dialog and optionally enters a comment, then clicks "Paraferen"
- **THEN** the system SHALL create a `parafeeractie`: `action` = `parafered`, `step` = 2, `actor` = M. Bakker's UID, `comment` = entered text (or null)
- **AND** the step SHALL advance to step 3

#### Scenario: Accordering step action

- **GIVEN** actor H. van den Berg is assigned to the final step (type: `accordering`) and `currentStep` equals that step number
- **WHEN** H. van den Berg confirms in the action dialog and clicks "Accorderen"
- **THEN** the system SHALL create a `parafeeractie`: `action` = `accorded`, `step` = N (final), `actor` = H. van den Berg's UID
- **AND** `voorstel.status` SHALL transition to `geaccordeerd`
- **AND** the steller SHALL receive a Nextcloud notification: "Voorstel '[onderwerp]' is volledig geaccordeerd"
- **AND** a PDF signature annotation SHALL be applied to the voorstel document (see REQ-PAA-005)

#### Scenario: Unauthorized actor blocked

- **GIVEN** user P. Janssen is NOT the actor assigned to the current voorstel step
- **WHEN** P. Janssen attempts to `POST /api/parafeer-actie` for that voorstel
- **THEN** the system SHALL return HTTP 403
- **AND** the response SHALL contain `{"message": "Not authorized for this parafering step"}` (static message — no exception detail)
- **AND** no `parafeeractie` object SHALL be created

---

### Requirement: REQ-PAA-002 — Return for Revision

The system SHALL allow the actor at the current step to return the voorstel to the steller for revision. A written reason SHALL be mandatory. The voorstel status SHALL transition to `teruggestuurd` and the steller SHALL be notified.

**Feature tier**: V1

#### Scenario: Return voorstel to steller

- **GIVEN** actor P. Janssen is at step 2 (type: `parafering`) of a voorstel with `onderwerp` = "Uitbreiding parkeerterrein Raadhuis"
- **WHEN** P. Janssen clicks "Terugsturen" in the action dialog, enters the reason "Financiële paragraaf ontbreekt. Kosten-batenanalyse toevoegen.", and confirms
- **THEN** the system SHALL create a `parafeeractie`: `action` = `returned`, `step` = 2, `actor` = P. Janssen's UID, `comment` = entered reason
- **AND** `voorstel.status` SHALL be set to `teruggestuurd`
- **AND** `voorstel.returnedFromStep` SHALL be set to 2 (for resumption on resubmit)
- **AND** the steller SHALL receive a Nextcloud notification: "Voorstel 'Uitbreiding parkeerterrein Raadhuis' is teruggestuurd door P. Janssen: Financiële paragraaf ontbreekt..."
- **AND** `voorstel.currentStep` SHALL remain at 2 (routing does not advance on return)

#### Scenario: Return reason is mandatory

- **GIVEN** actor P. Janssen opens the return dialog for a voorstel
- **WHEN** P. Janssen clicks "Terugsturen" without entering a reason
- **THEN** the frontend SHALL display a validation error: "Reden is verplicht bij terugsturen"
- **AND** the submit button SHALL remain disabled until a non-empty reason is entered
- **AND** no API call SHALL be made

---

### Requirement: REQ-PAA-003 — Delegate / Mandaat Actions

The system SHALL allow actors to record a parafering action on behalf of a principal (mandaatgever) when a valid mandate is configured. The `parafeeractie` record SHALL store `actorType` = `delegate`, `onBehalfOf` = principal UID, and `mandate` = mandate reference.

**Feature tier**: V1

#### Scenario: Record action as delegate

- **GIVEN** S. de Vos has a configured mandate (reference "DT-002/2026") allowing them to act on behalf of K. Vermeulen (assigned actor for step 1)
- **WHEN** S. de Vos opens the action dialog, selects "Namens K. Vermeulen (mandaat DT-002/2026)" in the delegate selector, enters advice text, and submits
- **THEN** the system SHALL create a `parafeeractie`:
  - `actor` = S. de Vos's UID (the actual user performing the action, from `IUserSession`)
  - `actorType` = `delegate`
  - `onBehalfOf` = K. Vermeulen's UID
  - `mandate` = `DT-002/2026`
  - `action` = `advised`
- **AND** the step SHALL advance as if K. Vermeulen had acted directly

#### Scenario: Delegate selector hidden when no mandates

- **GIVEN** actor M. Bakker has no configured mandates
- **WHEN** M. Bakker opens the parafering action dialog
- **THEN** the "Namens" delegate selector SHALL NOT be displayed
- **AND** `actorType` SHALL default to `user` in the submitted `parafeeractie`

---

### Requirement: REQ-PAA-004 — Action History Timeline

The system SHALL display a chronological read-only timeline of all `parafeeracties` for a voorstel, embedded in the voorstel detail view. Each entry SHALL show the actor, step number, action type, date/time, and any comment or advice text.

**Feature tier**: V1

#### Scenario: Timeline shows all recorded actions

- **GIVEN** a voorstel has 3 completed `parafeeracties`: step 1 advised by J. de Vries, step 2 parafered by M. Bakker, step 2 returned by P. Janssen (on a separate voorstel)
- **WHEN** the steller opens the voorstel detail view
- **THEN** the `ParafeerActieTimeline` section SHALL display all `parafeeracties` for this voorstel in ascending chronological order
- **AND** each entry SHALL show: actor display name, step number, action label (e.g. "Geadviseerd", "Geparafeerd", "Teruggestuurd"), timestamp, and comment/advice (if present)

#### Scenario: Timeline accessible to all voorstel participants

- **GIVEN** the voorstel detail page is open
- **WHEN** any authenticated user with access to the case views the voorstel
- **THEN** the timeline SHALL be readable (GET `/api/parafeer-actie?voorstel={id}` returns all records for that voorstel)
- **AND** the timeline SHALL be read-only — no edit or delete controls are shown

---

### Requirement: REQ-PAA-005 — Digital Signature on Voorstel Document

The system SHALL apply a PDF signature annotation to the voorstel's linked Nextcloud document when a step of type `accordering` is completed. The annotation SHALL record the actor UID, display name, timestamp, and step information. No external e-signature service is required for V1.

**Feature tier**: V1

#### Scenario: PDF signature applied on accordering

- **GIVEN** a voorstel has `document` = a Nextcloud file ID pointing to a PDF file, and the current step is of type `accordering`
- **WHEN** the actor completes the step with `action` = `accorded`
- **THEN** the backend SHALL read the voorstel's `document` file via `FileService`
- **AND** SHALL append a signature annotation block to the PDF containing: actor UID, actor display name, timestamp (ISO 8601), step number, and the text "Geaccordeerd via Procest parafeerroute"
- **AND** SHALL overwrite the existing Nextcloud file with the signed version
- **AND** the `parafeeractie` `comment` field SHALL record the signature metadata: "PDF handtekening aangebracht: [actor UID] op [timestamp]"

#### Scenario: Signing skipped when no document attached

- **GIVEN** a voorstel with `document` = null or empty (no document attached)
- **WHEN** an `accordering` step is completed
- **THEN** the `parafeeractie` SHALL be recorded and the step SHALL advance normally
- **AND** no PDF signing SHALL be attempted
- **AND** no error SHALL be returned to the actor — the absence of a document is a valid state

---

## Dependencies

- **parafeerroute-engine** (required): `ParafeerRouteService::completeStep` and `::activateStep` are called after each action to advance the voorstel through its route
- **OpenRegister**: `parafeeractie` and `voorstel` storage via `ObjectService` (3-arg API); automatic audit trail on every save
- **NotificatieService** (platform): Teruggestuurd notification to steller; geaccordeerd notification on final accordering
- **FileService** (platform): Nextcloud file read/write for PDF signature annotation

## Standards & References

- **ADR-000 (Data Model)**: `parafeeractie` and `voorstel` entity definitions — properties MUST match exactly; no new entities may be introduced
- **ADR-001 (Data Layer)**: All persistence via `ObjectService` (3-arg API); no custom Entity/Mapper
- **ADR-003 (Backend)**: Controller → Service → Mapper; `@spec` PHPDoc on every class and public method
- **ADR-004 (Frontend)**: Vue 2 Options API, `createObjectStore`, imports only from `@conduction/nextcloud-vue`
- **ADR-005 (Security)**: Per-object actor authorization — derive identity from `IUserSession`; never trust frontend-sent user IDs; `#[NoAdminRequired]` paired with manual actor check; return static error messages only
- **ADR-007 (i18n)**: All user-visible strings via `t(appName, '...')`; English keys; Dutch translations in `l10n/nl.json`
- **ADR-008 (Testing)**: PHPUnit tests for `ParafeerActieService` (≥ 3 methods); Newman collection covering 200, 403, 400 paths; Playwright scenario for each REQ-PAA requirement
- **BPMN 2.0 / CMMN 1.1**: `parafeeractie` maps to a HumanTask completion event in the sequential parafeerroute process
- **Archiefwet**: Immutable `parafeeractie` records and the automatic OpenRegister audit trail satisfy the accountability requirement for official Dutch municipal approval workflows
