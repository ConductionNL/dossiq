---
status: done
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

## Purpose

Provide VTH inspection (toezicht) checklist support in Dossiq: a versioned inspection-checklist schema linked to case types, an admin UI for managing checklists, completion of checklists into inspection rapporten stored as case documents, and a case-dashboard inspection panel showing progress and rapport history.

@e2e exclude Inspection checklists is V1; checklist schema and admin tab are not yet built in the Playwright-testable UI.

## Requirements

### Requirement: Inspection checklist schema

The system SHALL store inspection checklists as `inspectieChecklist` objects in OpenRegister with versioning, linked to case types.

**Feature tier**: V1
**ZGW mapping**: Custom extension (no ZGW equivalent)
**Schema.org**: schema:HowTo (checklist), schema:HowToStep (checklist item)

#### Scenario: Create inspection checklist

- **WHEN** an admin creates a new checklist "Bouwtoezicht fase 1 - Fundering" linked to case type "Toezichtzaak Bouw"
- **THEN** the system SHALL create an `inspectieChecklist` object with: name, caseType (reference), version (integer, starting at 1), status (draft), items (array of checklistItem)
- **THEN** each checklistItem SHALL support: order (integer), label (string), type (enum: ja_nee_nvt / tekst / getal / foto / meerkeuze), required (boolean), fotoRequired (boolean), options (array for meerkeuze), helpText (string)

#### Scenario: Version checklist

- **WHEN** an admin modifies an active checklist
- **THEN** the system SHALL create a new version (version + 1) in draft status
- **THEN** in-progress inspections SHALL continue using their original checklist version
- **THEN** only the latest active version SHALL be used for new inspections

### Requirement: Inspection checklist admin UI

The system SHALL provide an admin interface for creating and managing inspection checklists within the case type settings.

**Feature tier**: V1

#### Scenario: Configure checklist items

- **WHEN** the admin navigates to a Toezichtzaak case type's settings and opens the "Inspectiechecklists" tab
- **THEN** the system SHALL display a list of checklists for this case type
- **THEN** the admin SHALL be able to add checklist items with drag-and-drop reordering
- **THEN** each item SHALL have a configuration form with: label, type selector, required toggle, photo required toggle, help text, and options (for meerkeuze type)

#### Scenario: Seed checklists for VTH case types

- **WHEN** the VTH case type seed data is imported
- **THEN** the system SHALL create default inspection checklists:
  - "Bouwtoezicht fase 1 - Fundering": 4 items (fundering conform tekening, wapening, waterkering, maatvoering)
  - "Bouwtoezicht fase 2 - Ruwbouw": 5 items (metselwerk, kozijnen, dakconstructie, leidingen, brandwering)
  - "Bouwtoezicht fase 3 - Oplevering": 6 items (afwerking, installaties, brandveiligheid, toegankelijkheid, energielabel, as-built)

### Requirement: Inspection rapport creation

The system SHALL support completing inspection checklists to generate `inspectieRapport` objects stored as case documents.

**Feature tier**: V1
**Schema.org**: schema:Report (rapport), schema:ReviewAction (inspection)

#### Scenario: Complete inspection checklist

- **WHEN** an inspector opens a planned inspection on case "2026-089" and fills in all checklist items
- **THEN** the system SHALL record an `inspectieRapport` with: case (reference), checklist (reference), inspector (user UID), inspectionDate (datetime), location (string), items (array of completed results per item)
- **THEN** each completed item SHALL record: itemId, result (pass/fail/nvt), comment (string), measurement (number, if type=getal), photos (array of Nextcloud file IDs)
- **THEN** the overall result SHALL be automatically determined: "conform" (0 failed), "niet_conform" (1+ failed), "deels_conform" (some failed, some nvt)

#### Scenario: Photo capture on failed items

- **WHEN** an inspector marks a checklist item with fotoRequired=true as "nee" (failed)
- **THEN** the system SHALL require at least one photo before the rapport can be submitted
- **THEN** photos SHALL be uploaded to the case folder in Nextcloud Files
- **THEN** each photo SHALL be linked to the specific checklist item in the rapport

#### Scenario: Follow-up task on non-conformity

- **WHEN** an inspector submits a rapport with result "niet_conform" (2 failed items)
- **THEN** the system SHALL automatically create a task on the case: "Opvolging vereist: 2 afwijkingen geconstateerd"
- **THEN** the task SHALL reference the inspectieRapport

### Requirement: Inspection panel on case dashboard

The system SHALL display an inspection panel on the case dashboard for Toezicht case types showing inspection progress and rapport history.

**Feature tier**: V1

#### Scenario: Display inspection progress

- **WHEN** a user views the case dashboard for a Toezichtzaak Bouw with 3 inspection phases
- **THEN** the "Inspecties" panel SHALL show: inspection progress bar ("Inspectie 1/3 voltooid"), list of phases with status (completed/current/pending)
- **THEN** completed phases SHALL show: date, inspector name, result badge (conform=green, niet_conform=red, deels_conform=orange)

#### Scenario: Expand rapport details

- **WHEN** a user clicks on a completed inspection in the panel
- **THEN** the system SHALL expand to show individual checklist item results: item label, result (pass/fail/nvt), comment, linked photos
- **THEN** failed items SHALL be highlighted with a warning icon

#### Scenario: Multiple inspections per phase

- **WHEN** a case has multiple inspectieRapporten for the same phase (re-inspection after non-conformity)
- **THEN** the panel SHALL show all rapporten for that phase in chronological order
- **THEN** the most recent rapport SHALL determine the current phase status

### Requirement: Inspection result submission is authorized from stored state

The system SHALL decide who may submit an inspection result for a case from
data it has stored, never from the submitted request. `POST
/api/vth/cases/{id}/inspection-result` SHALL admit only the case's stored
assignee and members of the admin group, and SHALL refuse every other
authenticated caller.

This requirement exists because the endpoint shipped with a guard that read
the permitted inspector out of the request body (issue #799). A caller who
omitted the field, or who named themselves, passed. The rule below is written
down so the next implementation cannot quietly choose a different one.

**Feature tier**: V1

#### Scenario: The case's assignee submits a result

- **GIVEN** case `2026-089` whose stored `assignee` is `inspecteur-a`
- **WHEN** `inspecteur-a` posts an inspection result to that case
- **THEN** the system SHALL accept the submission and store an
  `inspectionResult` whose `case` is `2026-089` and whose `completedBy` is
  `inspecteur-a`

#### Scenario: Another authenticated account is refused

- **GIVEN** the same case, and an authenticated account `buitenstaander` that
  is neither its `assignee` nor a member of the admin group
- **WHEN** `buitenstaander` posts an inspection result to that case
- **THEN** the system SHALL respond `403 Forbidden`
- **THEN** no `inspectionResult` SHALL be written

#### Scenario: Omitting the inspector field does not skip the check

- **GIVEN** the same case and the same account `buitenstaander`
- **WHEN** `buitenstaander` posts an inspection result carrying no inspector
  field of any kind
- **THEN** the system SHALL still respond `403 Forbidden`, because the decision
  is taken before the payload is read and no absent field can influence it

#### Scenario: The case cannot be resolved

@e2e exclude every branch of this one is an infrastructure failure a browser cannot stage: an absent OpenRegister, an unconfigured case schema, a case uuid with no row. `CaseAccessGuardReadAccessTest` drives all three against the guard directly, which is where the decision is taken.

- **WHEN** a submission names a case that does not exist, or OpenRegister is
  unavailable, or the case schema is not configured
- **THEN** the system SHALL refuse the submission
- **THEN** an unresolvable case SHALL never be treated as an unrestricted one

#### Scenario: Creating an advice request is authorized the same way

- **GIVEN** case `2026-089` whose stored `assignee` is `inspecteur-a`
- **WHEN** an authenticated account that is neither that assignee nor an admin
  posts to `/api/vth/cases/2026-089/advice-requests`
- **THEN** the system SHALL respond `403 Forbidden` and SHALL send no adviseur
  notification

### Requirement: Inspection results are stored under the schema's own property names

The system SHALL write an `inspectionResult` using the property names the
schema declares. `case` and `checklist` are required properties of that
schema, so a payload spelling them `caseRef` and `checklistRef` is rejected by
OpenRegister's validator and no result is ever recorded. Reads SHALL filter on
the same names.

**Feature tier**: V1

#### Scenario: A submitted result is readable back

- **WHEN** a permitted caller submits an inspection result for a case
- **THEN** `GET /api/vth/cases/{id}/inspection-results` SHALL return that
  result for the same case

<!-- BEGIN retrofit-2026-05-24-inspection-checklists -->

## Execution Surface (retrofit)

### REQ-001: InspectionController SHALL expose the in-field inspection lifecycle endpoints

`OCA\Dossiq\Controller\InspectionController` SHALL provide endpoints for the field inspector: `index()` (list assigned inspections), `captureLocation($id)` (record GPS), `completeChecklistItem($id, $itemId)` (mark one item with conformity + comment + mandatory photos), `addPhoto($id)` (attach photo with EXIF metadata), and `complete($id)` (finalise the inspection with overall conclusion). Each endpoint SHALL delegate to `InspectionService` or `ChecklistService` and SHALL enforce that the calling user is the assigned inspector for the record.

#### Scenario: Inspector completes an item with mandatory photo
- **GIVEN** a checklist item flagged `photoRequired: true`
- **WHEN** the inspector calls `completeChecklistItem` without attaching at least one photo
- **THEN** the controller SHALL respond `400 Bad Request` and the item SHALL remain incomplete

### REQ-002: InspectionService SHALL implement field-side state mutations

`OCA\Dossiq\Service\InspectionService` SHALL implement `getInspections()` (filter by user/case/status), `captureLocation()` (persist GPS + accuracy + timestamp), `addPhoto()` (attach a photo with EXIF metadata + GPS extracted from the file), and `completeInspection()` (mark all items final, persist conclusion, transition the parent case if configured). Location and photo timestamps SHALL be persisted as `DateTime` and tagged with the capturing user — never overwritten by a later edit.

#### Scenario: Complete an inspection with non-conformities
- **GIVEN** an inspection with at least one item flagged non-conforming
- **WHEN** `completeInspection($inspection, 'Niet conform; correctie vereist')` is called
- **THEN** the inspection SHALL be marked completed and a corrective-action workflow SHALL be triggered on the parent case if one is configured for non-conform outcomes

### REQ-003: ChecklistService SHALL compute item completion + progress + conformity summary

`OCA\Dossiq\Service\ChecklistService` (top-level, separate from `lib/Service/Inspection/ChecklistService.php` which handles template lifecycle) SHALL provide `completeItem()`, `getProgress()` (returns `{completed, total, percent}`), `validateCompletion()` (enforces mandatory items + photo-required rules), and `getConformitySummary()` (returns `{conforming, nonConforming, na, pending}` counts). All four methods SHALL be pure with respect to the checklist payload — no I/O — so they are reusable for both server-side validation and dry-run preview.

#### Scenario: Pure progress calculation
- **WHEN** `ChecklistService::getProgress($checklist)` is called
- **THEN** the result SHALL be derivable from the checklist payload alone (no database lookups) so the same calculation can run in unit tests

Notes
- The duplicate-file callout (Service/ChecklistService.php vs Service/Inspection/ChecklistService.php) is preserved: the top-level service handles per-run progress, the namespaced one handles templates. Consolidation deferred to a future refactor change.

<!-- END retrofit-2026-05-24-inspection-checklists -->
