---
status: draft
---
# parafering-dashboard Specification

## Purpose

Implement a parafering dashboard and personal inbox for the Procest app. The secretariaat gains a centralized overview at `/voorstellen` showing all active voorstellen with their current parafering step, responsible actor, days waiting, and overall progress. Overdue voorstellen are highlighted and the secretariaat can send reminder notifications. Individual actors see their personal "Ter parafering" inbox in the MyWork view with direct action links. A "Voorstellen" sidebar navigation item provides the entry point.

## Context

The `parafering-actions` change established the actor-facing action recording flow and `parafeerroute-engine` implemented the routing engine and step activation. Actors receive Nextcloud tasks when their step is activated and can record their decisions, but there is no centralized monitoring view. Secretariaat must open individual case detail pages to determine which voorstellen are stuck and who is responsible. This change implements the read-only dashboard surfaces and the reminder mechanism, consuming existing `voorstel` and `parafeeractie` data without introducing new entities.

## Requirements

---

### REQ-PDB-001: Secretariaat Parafering Overview

The system SHALL provide a parafering dashboard at `/voorstellen` listing all active voorstellen with their current parafering status, intended for the secretariaat role. Voorstellen overdue on any step SHALL be highlighted. The list SHALL be sortable by multiple columns.

**Feature tier**: V1

#### REQ-PDB-001-001: Overview with active voorstellen

- **GIVEN** the secretariaat opens the parafering dashboard at `/voorstellen` and there are 8 active (`in_parafering`) voorstellen
- **WHEN** the page loads
- **THEN** each voorstel row SHALL display: onderwerp, current step name (derived from `routeSnapshot[currentStep-1].label`), waiting actor (derived from `routeSnapshot[currentStep-1].actor`), days in current step (calculated from `createdAt` of the last `parafeeractie` for that step or `voorstel.updatedAt` if none), and overall progress as "stap X/Y" (where X = `currentStep` and Y = total steps in `routeSnapshot`)
- **AND** voorstellen where days in current step exceeds the configured threshold SHALL be highlighted with a warning indicator (e.g., yellow badge or icon)
- **AND** the list SHALL be sortable by: onderwerp (alphabetical), status (alphabetical), days waiting (numeric), and steller (alphabetical) — clicking a column header toggles ascending/descending sort

#### REQ-PDB-001-002: Empty dashboard

- **GIVEN** there are no voorstellen with `status` = `in_parafering`
- **WHEN** the secretariaat opens the parafering dashboard
- **THEN** the dashboard SHALL display the message: "Geen actieve voorstellen"
- **AND** no table rows SHALL be rendered
- **AND** the sort controls SHALL still be rendered (not conditionally removed)

---

### REQ-PDB-002: Personal Parafering Inbox

The system SHALL provide a personal parafering inbox showing voorstellen awaiting the current user's action. This SHALL be integrated into the MyWork view as a dedicated "Ter parafering" section. Each item SHALL be directly actionable without navigating to the full voorstel detail view.

**Feature tier**: V1

#### REQ-PDB-002-001: View personal inbox

- **GIVEN** wethouder Van Dam (UID: `wethouder.van.dam`) has 3 voorstellen where `routeSnapshot[currentStep-1].actor` = `wethouder.van.dam`
- **WHEN** Van Dam opens the MyWork view
- **THEN** a "Ter parafering" section SHALL be visible, listing the 3 voorstellen
- **AND** each item SHALL display: onderwerp, case reference (derived from `voorstel.case`), steller (user display name), and waiting-since date (derived from the `createdAt` of the step activation `parafeeractie` or `voorstel.updatedAt`)
- **AND** each item SHALL show direct action buttons: "Paraferen" (or "Adviseren" / "Accorderen" depending on step type) and "Terugsturen", opening `ParafeerActieDialog` inline without navigating to the full voorstel detail view

#### REQ-PDB-002-002: No pending parafering

- **GIVEN** the current user has no voorstellen where they are the active step actor
- **WHEN** the user opens the MyWork view
- **THEN** the "Ter parafering" section SHALL display the message: "Geen voorstellen ter parafering"
- **AND** the section heading "Ter parafering" SHALL still be visible (section is always rendered, only content changes)

---

### REQ-PDB-003: Send Parafering Reminder

The system SHALL allow the secretariaat to send a reminder to the actor who has not yet acted on their parafering step. The reminder SHALL be a Nextcloud notification sent to the actor, and the action SHALL be logged in the parafering audit trail of the voorstel.

**Feature tier**: V1

#### REQ-PDB-003-001: Send reminder to overdue actor

- **GIVEN** a voorstel "Omgevingsvergunning uitbreiding bedrijventerrein De Hoek" has been waiting at step "Afdelingshoofd parafeert" (actor: `p.janssen`) for 5 days, which exceeds the configured overdue threshold
- **AND** the secretariaat clicks "Herinnering sturen" on that voorstel's row
- **THEN** the system SHALL send a Nextcloud notification to `p.janssen` with the message: "Voorstel 'Omgevingsvergunning uitbreiding bedrijventerrein De Hoek' wacht op uw parafering (5 dagen)"
- **AND** the reminder SHALL be logged in the voorstel's audit trail (as a note via OpenRegister's built-in `notes` field or `auditTrail`)
- **AND** the "Herinnering sturen" button SHALL show a brief confirmation state (e.g., "Verstuurd") after the notification is sent
- **AND** the button SHALL be re-enabled after the confirmation period to allow sending additional reminders

---

### REQ-PDB-004: Voorstel List Navigation

The system SHALL add a "Voorstellen" navigation item to the Procest sidebar, linking to the parafering dashboard at `/voorstellen`. The item SHALL be visible to all authenticated Procest users.

**Feature tier**: V1

#### REQ-PDB-004-001: Navigate to voorstellen

- **GIVEN** an authenticated user is in the Procest app
- **WHEN** the user clicks "Voorstellen" in the Procest sidebar navigation
- **THEN** the app SHALL navigate to `/voorstellen`
- **AND** the voorstel list SHALL display only voorstellen from cases the user has access to (OpenRegister built-in access control)
- **AND** the "Voorstellen" navigation item SHALL be highlighted as active when the current route is `/voorstellen`

---

## Dependencies

- **parafering-actions** (required): `ParafeerActieDialog.vue` is reused in `ParafeerInbox.vue` for quick in-inbox action taking; `parafeerActieApi.js` reused for `listActions` to derive days-waiting values
- **parafeerroute-engine** (required): `routeSnapshot` and `currentStep` fields on `voorstel` are consumed to display step name, waiting actor, and progress indicators in both the dashboard and inbox
- **OpenRegister**: `voorstel` listing with `status=in_parafering` filter and `parafeeractie` retrieval via `ObjectService`; automatic audit trail and notes on every save
- **NotificatieService** (platform): Used by `ParafeerHerinneringService` to send targeted reminder notifications to step actors

## Standards & References

- **ADR-000 (Data Model)**: `voorstel` and `parafeeractie` entity definitions — properties MUST match exactly; no new entities may be introduced
- **ADR-001 (Data Layer)**: All persistence via `ObjectService` (3-arg API); no custom Entity/Mapper
- **ADR-003 (Backend)**: Controller → Service → Mapper; `@spec` PHPDoc on every class and public method
- **ADR-004 (Frontend)**: Vue 2 Options API, `createObjectStore`, imports only from `@conduction/nextcloud-vue`
- **ADR-005 (Security)**: Group membership check for secretariaat-only reminder endpoint; derive identity from `IUserSession`; return static error messages only
- **ADR-007 (i18n)**: All user-visible strings via `t(appName, '...')`; English keys; Dutch translations in `l10n/nl.json`
- **ADR-008 (Testing)**: PHPUnit tests for `ParafeerHerinneringService` (≥ 3 methods); Newman collection covering 201 and 400 paths; Playwright scenario for each REQ-PDB requirement
- **Archiefwet**: Reminder events logged via OpenRegister audit trail satisfy the accountability requirement for official Dutch municipal approval workflows
