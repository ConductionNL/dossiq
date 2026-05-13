# advice-management Specification

## Purpose

Enable structured advice request management (adviezen) within Procest case flows. Behandelaars can request internal and external advice on cases, track deadlines, and the system enforces workflow guards to ensure all advice is received before cases progress. This covers the full lifecycle: requesting, tracking, receiving, and handling expired advice.

## Context

Dutch municipal case handling (VTH, bezwaar, vergunningverlening) regularly requires formal advice from internal specialists (welstandscommissie, juridische dienst) or external authorities (Veiligheidsregio, RUD). Without structured tracking, these advice requests are managed ad-hoc in email, deadlines are missed, and cases advance prematurely. The `adviesAanvraag` entity (ADR-000) provides the data model; this spec defines the behaviour.

## ADDED Requirements
### Requirement: REQ-ADV-001 — Advice Request Schema

The system SHALL store advice requests as `adviesAanvraag` objects in OpenRegister, supporting the full intern/extern advice lifecycle with deadline tracking and document attachment.

#### Scenario: Create internal advice request

- **GIVEN** the behandelaar is viewing a case dashboard for case `zaak-0042`
- **AND** they click "Advies aanvragen" and select an internal adviseur (e.g., welstandscommissie)
- **WHEN** they submit the advice request form
- **THEN** the system SHALL create an `adviesAanvraag` object with:
  - `case` = UUID of `zaak-0042`
  - `adviseur` = the selected user UID
  - `type` = `intern`
  - `onderwerp` = the entered subject
  - `deadline` = the entered date
  - `status` = `aangevraagd`
  - `requestedAt` = current datetime
- **AND** a task SHALL be created for the adviseur: "Advies uitbrengen voor [case identifier]"
- **AND** the case activity log SHALL record: "Advies aangevraagd bij [adviseur]"

#### Scenario: Create external advice request

- **GIVEN** the behandelaar requests advice from an external party (e.g., Veiligheidsregio Amsterdam-Amstelland)
- **WHEN** they submit with `type` = `extern` and an organization name as adviseur
- **THEN** the system SHALL create an `adviesAanvraag` with `type` = `extern` and the organization name stored in `adviseur`
- **AND** a Nextcloud reminder notification SHALL be scheduled 3 days before the deadline
- **AND** if the deadline passes without a response, an escalation notification SHALL be sent to the behandelaar and teamleider

#### Scenario: Receive and process advice

- **GIVEN** the adviseur (or behandelaar on their behalf) uploads an advice document to the `adviesAanvraag` object
- **AND** marks the request as completed
- **WHEN** the status transition is confirmed
- **THEN** the `adviesAanvraag` status SHALL change to `ontvangen`
- **AND** `receivedAt` SHALL be set to the current datetime
- **AND** the uploaded file SHALL be stored as `adviesDocument` (Nextcloud file ID)
- **AND** the behandelaar SHALL receive a Nextcloud notification: "Advies ontvangen voor [case identifier]"
- **AND** the case activity log SHALL record: "Advies ontvangen van [adviseur]"

#### Scenario: Advice deadline expiry

- **GIVEN** an `adviesAanvraag` with `status` = `aangevraagd` exists
- **WHEN** the `AdviceDeadlineJob` runs and the `deadline` date has passed
- **THEN** the status SHALL change to `verlopen`
- **AND** a task SHALL be created for the behandelaar: "Advies verlopen: beoordeel of vergunningprocedure kan doorgaan zonder dit advies"
- **AND** an escalation notification SHALL be sent to the behandelaar and teamleider

#### Scenario: Deadline reminder notification

- **GIVEN** an `adviesAanvraag` with `status` = `aangevraagd` and a `deadline` that is 3 days away
- **WHEN** the `AdviceDeadlineJob` runs
- **THEN** a Nextcloud notification SHALL be sent to the behandelaar: "Herinnering: advies van [adviseur] verwacht op [deadline]"

---

### Requirement: REQ-ADV-002 — Advice Panel on Case Dashboard

The system SHALL display an "Adviezen" panel on the case detail view showing all advice requests with their status, type, and deadline information.

#### Scenario: Display advice overview

- **GIVEN** a user views the case detail for a case with 3 `adviesAanvraag` records
- **WHEN** the "Adviezen" panel renders
- **THEN** all 3 advice requests SHALL be listed, each showing:
  - Adviseur name (user display name for intern, organization name for extern)
  - Type badge: `intern` (grey) or `extern` (blue)
  - Status badge: `aangevraagd` (blue), `ontvangen` (green), `verlopen` (red)
  - Deadline date formatted as DD-MM-YYYY
- **AND** requests with a deadline in the past and status `aangevraagd` or `verlopen` SHALL be highlighted in red
- **AND** the number of days overdue SHALL be shown next to overdue requests

#### Scenario: Quick actions on advice panel

- **GIVEN** the behandelaar views the "Adviezen" panel
- **WHEN** they interact with an individual advice request row
- **THEN** the following quick actions SHALL be available depending on status:
  - `aangevraagd` → "Herinnering sturen" (sends reminder notification to adviseur)
  - `ontvangen` → "Bekijk advies" (opens the advice document)
  - `aangevraagd` with a document uploaded → "Markeer als ontvangen" (transitions to `ontvangen`)
- **AND** no quick actions SHALL be shown for requests with status `verlopen` except a link to the generated task

#### Scenario: Empty state

- **GIVEN** a case has no `adviesAanvraag` records
- **WHEN** the "Adviezen" panel renders
- **THEN** a CnEmptyState SHALL be shown with the message "Geen adviezen aangevraagd"
- **AND** the "Advies aanvragen" button SHALL be visible and functional

---

### Requirement: REQ-ADV-003 — Advice Request Form

The system SHALL provide a form dialog for creating advice requests directly from the case detail view.

#### Scenario: Create advice request dialog

- **GIVEN** the behandelaar clicks "Advies aanvragen" on the case dashboard
- **WHEN** the dialog opens
- **THEN** the dialog SHALL present the following fields:
  - `type` toggle: "Intern" / "Extern" (default: Intern)
  - `adviseur`: user picker (when Intern), free text input (when Extern)
  - `onderwerp`: text input (required)
  - `deadline`: date picker defaulting to 2 weeks from today
  - `questions`: textarea for specific questions (optional)
- **AND** the form SHALL validate that `adviseur` and `deadline` are filled before enabling submission
- **AND** on submission, the `adviesAanvraag` SHALL be created and the panel SHALL refresh

#### Scenario: Advice guard on workflow transition

- **GIVEN** a workflow transition on a case is configured with a guard of type `adviesGuard`
- **WHEN** the behandelaar attempts to trigger the transition
- **THEN** the system SHALL query all `adviesAanvraag` records for the case
- **AND** if any record has `status` = `aangevraagd`, the transition SHALL be blocked
- **AND** the guard violation message SHALL list each pending advice: "[adviseur]: advies verwacht voor [deadline DD-MM-YYYY]"
- **AND** the transition button SHALL be disabled with a tooltip explaining the guard

#### Scenario: Form accessibility

- **GIVEN** the behandelaar navigates the advice request dialog using only a keyboard
- **WHEN** they tab through the form fields
- **THEN** all fields SHALL be reachable and operable via keyboard
- **AND** focus SHALL return to the "Advies aanvragen" button after the dialog closes
- **AND** all form labels SHALL be programmatically associated with their inputs (WCAG AA)

---

## Dependencies

- OpenRegister: `adviesAanvraag` object storage, `case` relation, file attachment
- NotificatieService (platform): Nextcloud notifications for adviseur, behandelaar, teamleider
- TasksController (platform): Auto-create tasks linked to the case
- WorkflowEngine (platform): Guard hook on `workflowTemplate.transitions[]`

## Standards & References

- **ADR-000 (Data Model)**: `adviesAanvraag` entity definition — properties MUST match exactly
- **ADR-001 (Data Layer)**: All persistence via `ObjectService` (3-arg API), no custom Entity/Mapper
- **ADR-004 (Frontend)**: Vue 2 Options API, `createObjectStore`, `@conduction/nextcloud-vue` imports only
- **ADR-015 (Common Patterns)**: SPDX headers, error handling, authorization checks, translation keys
- **WCAG AA**: Advice dialog must be keyboard navigable, labels associated, color not sole indicator
- **ZGW**: Advice requests are a custom extension to zaakgericht werken — no standard ZGW API mapping; stored as custom objects linked to the zaak via the `case` relation
