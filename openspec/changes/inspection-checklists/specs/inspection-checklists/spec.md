---
status: proposed
---
# inspection-checklists Specification

## Purpose

Enable VTH inspectors in Procest to conduct structured on-site inspections using configurable checklist templates linked to case types. The system supports the full inspection lifecycle: administrators define checklist templates with versioning, inspectors execute inspections and record findings with photos, the system auto-calculates pass/fail results, statistical sampling rounds support quality hold/release decisions, and supervisor approval gates ensure niet_conform findings are reviewed before triggering case status transitions.

## Context

Dutch VTH (Vergunningverlening, Toezicht, Handhaving) processes require documented evidence of inspections for legal validity. Building inspectors checking construction phases (bouwfases), environmental inspectors verifying permit compliance, and food safety inspectors checking horeca establishments all follow structured checklists. Procest already provides the `inspectieChecklist` and `inspectieRapport` schemas (ADR-000 Group 7); this spec defines the functional requirements for the service layer and UI. The statistical sampling and quality hold/release requirements mirror ISO 2859-1 attribute sampling as applied in Dutch construction phase release (gereedmelding) workflows. The approval workflow requirement aligns with the Landelijke Handhavingsstrategie (LHS) requirement for supervisory sign-off on enforcement findings.

## Requirements

### Requirement 1: Inspection checklist template management

Administrators MUST be able to create, edit, and version inspection checklist templates linked to a case type.

#### REQ-ICL-001 Scenario 1.1: Create a new checklist template

- GIVEN an administrator is on the inspection checklists settings page
- WHEN they click "Nieuwe checklist" and fill in the name, select a case type, and add at least one item
- THEN the system MUST create a new `inspectieChecklist` object with `status: draft` and `version: 1`
- AND the checklist MUST be visible in the template list filtered by the selected case type
- AND the checklist MUST NOT be available for use in inspections while in `draft` status

#### REQ-ICL-001 Scenario 1.2: Add and reorder checklist items

- GIVEN an administrator is editing a checklist template
- WHEN they add a new item with label, type (ja_nee_nvt / tekst / getal / foto / meerkeuze), required flag, fotoRequired flag, and optional helpText
- THEN the item MUST be appended to the `items` array with the next sequential `order` value
- AND the administrator MUST be able to drag items to reorder them, updating `order` values accordingly
- AND for `meerkeuze` type items, the administrator MUST be able to define the list of answer options

#### REQ-ICL-001 Scenario 1.3: Publish a checklist to make it active

- GIVEN a checklist template in `draft` status with at least one required item
- WHEN the administrator clicks "Publiceren"
- THEN the system MUST set `status: active`
- AND the checklist MUST become selectable when starting a new inspection on a case of the linked case type
- AND only one version of a given checklist name per case type MAY be `active` at the same time

#### REQ-ICL-001 Scenario 1.4: Edit an active checklist creates a new version

- GIVEN a checklist template with `status: active` and `version: 1`
- WHEN the administrator edits any item and saves
- THEN the system MUST create a new `inspectieChecklist` object with `version: 2` and `status: draft`
- AND set the original version to `status: archived`
- AND existing `inspectieRapport` records linked to version 1 MUST continue to reference version 1
- AND the new version MUST go through the draft → active lifecycle before it can be used

#### REQ-ICL-001 Scenario 1.5: Archive a checklist

- GIVEN a checklist template in `active` status
- WHEN the administrator clicks "Archiveren"
- THEN the system MUST set `status: archived`
- AND the checklist MUST no longer appear as an option when starting new inspections
- AND existing `inspectieRapport` records linked to the archived checklist MUST remain intact

---

### Requirement 2: Inspection execution and report generation

Inspectors MUST be able to conduct inspections on cases, recording per-item results, photos, and remarks, with the system automatically calculating the overall result.

#### REQ-ICL-002 Scenario 2.1: Start an inspection from a case

- GIVEN a case of a type that has at least one active `inspectieChecklist`
- WHEN the inspector clicks "Nieuwe inspectie" in the inspection section of the case detail
- THEN the system MUST present a selection of active checklists for the case type
- AND after selecting a checklist, MUST create a new `inspectieRapport` object linked to the case and checklist
- AND display the inspection form with all checklist items in order

#### REQ-ICL-002 Scenario 2.2: Record a ja_nee_nvt item result

- GIVEN an inspector is filling out an item of type `ja_nee_nvt`
- WHEN they select "ja", "nee", or "nvt"
- THEN the result MUST be stored in `inspectieRapport.items[n].result`
- AND if `fotoRequired` is true and the result is "nee", the inspector MUST upload at least one photo before they can proceed to the next item
- AND the inspector MUST be able to add a free-text comment per item regardless of result

#### REQ-ICL-002 Scenario 2.3: Record a getal measurement

- GIVEN an inspector is filling out an item of type `getal`
- WHEN they enter a numeric value
- THEN the system MUST validate that the entry is a valid number
- AND store the value in `inspectieRapport.items[n].result` as a number
- AND display the item's `helpText` as a hint below the input field

#### REQ-ICL-002 Scenario 2.4: Attach photos to a checklist item

- GIVEN an inspector is on an inspection item with `fotoRequired: true` or who chooses to add photos
- WHEN they upload one or more photos from their device
- THEN the photos MUST be attached to the `inspectieRapport` via `FileService`
- AND the file IDs MUST be stored both in `inspectieRapport.photos` and in `inspectieRapport.items[n].photos` for the specific item
- AND thumbnails of the uploaded photos MUST be shown inline in the inspection form

#### REQ-ICL-002 Scenario 2.5: Submit a completed inspection report

- GIVEN an inspector has answered all required items
- WHEN they click "Inspectie afronden"
- THEN `InspectieService::calculateResult()` MUST evaluate all item results and set `result` (conform / niet_conform / deels_conform) and `failedItems` count
- AND the `inspectieRapport` MUST be saved with `inspectionDate` set to the current timestamp
- AND if the result is `niet_conform` or `deels_conform`, `followUpRequired` MUST be set to `true`
- AND the submitted report MUST appear in the case's inspection section with a colored result badge (groen = conform, rood = niet_conform, oranje = deels_conform)

#### REQ-ICL-002 Scenario 2.6: Record inspection location

- GIVEN an inspector is on the inspection form
- WHEN they click "Locatie vastleggen"
- THEN the system MUST capture the GPS coordinates from the browser Geolocation API (or allow manual address entry as fallback)
- AND store the location in `inspectieRapport.location`
- AND display the recorded location in the completed report

---

### Requirement 3: Statistical sampling and quality hold/release workflow

The system MUST support statistical sampling inspection rounds where a subset of checklist items is evaluated, with a hold placed on the case until the sample result is acceptable.

#### REQ-ICL-003 Scenario 3.1: Mark checklist items as sample-eligible

- GIVEN an administrator is editing a checklist template
- WHEN they toggle the "Steekproef" flag on one or more items
- THEN those items MUST be marked as sample-eligible in the `items` array
- AND the checklist MUST have a configurable `sampleSize` property (percentage or fixed count)
- AND non-sample items MUST always appear in every inspection (both full and sample rounds)

#### REQ-ICL-003 Scenario 3.2: Start a statistical sampling inspection

- GIVEN a case with an active checklist that has sample-eligible items configured
- WHEN the inspector starts a new inspection and selects "Steekproef inspectie"
- THEN `InspectieService::selectSampleItems()` MUST randomly select the configured number/percentage of sample-eligible items
- AND the inspection form MUST show only: all non-sample required items + the selected sample subset
- AND the sampled item selection MUST be recorded in `inspectieRapport.items` (only sampled items present)

#### REQ-ICL-003 Scenario 3.3: Place a quality hold on the case

- GIVEN a sampling inspection results in `niet_conform`
- WHEN the report is submitted
- THEN the system MUST place a quality hold on the case by creating a `task` with title "Kwaliteitsborging: steekproef niet conform — wacht op vrijgave" assigned to the case's toezichthouder role
- AND the case MUST display a "Kwaliteitsborging" status indicator in the case header
- AND no workflow status transition that advances the case to the next phase MAY succeed while the hold task is open

#### REQ-ICL-003 Scenario 3.4: Release the quality hold

- GIVEN a case has an open quality hold task
- AND the inspector has remediated the non-conformities and conducted a follow-up inspection that resulted in `conform`
- WHEN the supervisor marks the quality hold task as complete
- THEN the hold MUST be lifted and the case MUST be advanceable to the next status
- AND a case activity entry MUST be added: "Kwaliteitsborging vrijgegeven door [supervisor name] op [date]"

#### REQ-ICL-003 Scenario 3.5: Sampling history is auditable

- GIVEN multiple sampling inspections have been conducted on a case
- WHEN an auditor views the case's inspection section
- THEN all inspection reports MUST be listed with their type (volledig / steekproef), inspector, date, and result
- AND the specific items selected for each sampling round MUST be visible in the individual report detail view

---

### Requirement 4: Approval workflows in checklists

Completed inspection reports with niet_conform results MUST require supervisor approval before triggering case status transitions.

#### REQ-ICL-004 Scenario 4.1: Supervisor approval task created automatically

- GIVEN an inspector submits an `inspectieRapport` with `result: niet_conform`
- WHEN the report is saved
- THEN `InspectieService` MUST automatically create a `task` object on the case:
  - title: "Goedkeuring inspectie vereist — [inspector display name] — [checklist name]"
  - assignee: the user with role type "toezichthouder" on the case (if no toezichthouder role, leave unassigned)
  - description: includes the report UUID, failedItems count, and a link to the report
- AND the inspector MUST receive a confirmation: "Rapport opgeslagen. Toezichthouder wordt genotificeerd voor akkoord."
- AND `NotificationService` MUST notify the assigned toezichthouder

#### REQ-ICL-004 Scenario 4.2: Supervisor reviews and approves the inspection report

- GIVEN a supervisor has an open approval task for an inspection report
- WHEN they open the task from their My Work view and navigate to the linked inspection report
- THEN they MUST see the full report detail with all item results, photos, and the overall result
- AND they MUST see two action buttons: "Goedkeuren" and "Terugsturen"
- AND clicking "Goedkeuren" MUST mark the approval task as complete and allow the case to advance
- AND clicking "Terugsturen" MUST add a comment field (required) and reassign the task to the original inspector for correction

#### REQ-ICL-004 Scenario 4.3: Returned report can be corrected and resubmitted

- GIVEN a supervisor has returned an inspection report to the inspector with a comment
- WHEN the inspector opens the case and navigates to the returned report
- THEN they MUST see the supervisor's return comment
- AND they MUST be able to edit the in-progress report items and re-upload photos
- AND resubmitting MUST recalculate `result` and `failedItems`
- AND a new approval task MUST be created for the supervisor if the result remains `niet_conform`

#### REQ-ICL-004 Scenario 4.4: Conform reports do not require approval

- GIVEN an inspector submits an `inspectieRapport` with `result: conform`
- WHEN the report is saved
- THEN the system MUST NOT create an approval task
- AND if the linked workflow template has a transition guard `inspectieResultaat: conform`, the case MUST be eligible for the transition immediately
- AND an activity entry MUST be added to the case: "Inspectie conform — [checklist name] — [date]"

#### REQ-ICL-004 Scenario 4.5: Deels conform result requires approval with recommendation

- GIVEN an inspector submits an `inspectieRapport` with `result: deels_conform`
- WHEN the report is saved
- THEN the system MUST create an approval task (same as niet_conform flow per Scenario 4.1)
- AND the approval task description MUST include: number of failed items, number of passed items, and the list of failed item labels
- AND the supervisor MUST be able to approve with a condition note (free text) that is stored on the rapport as `remarks` appended text
