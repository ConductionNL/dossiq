# inspection-forms-via-forms-leaf Specification

## Purpose
TBD - created by archiving change migrate-inspection-forms-to-forms-leaf. Update Purpose after archive.
## Requirements
### Requirement: Checklist And Advice Forms Render Through The OR Forms Leaf

Procest SHALL render inspection checklist items and advice/consultation request forms through
OpenRegister's `forms` integration leaf (ADR-019). Procest SHALL NOT hand-render form question
inputs in `InspectionChecklistPanel.vue` / `DocumentChecklist.vue` after this migration.

#### Scenario: Checklist items render via the forms leaf

- **GIVEN** an inspection checklist template and the OR forms leaf available
- **WHEN** an inspector opens the checklist on a case
- **THEN** the checklist items SHALL be rendered by the forms leaf
- **AND** responses SHALL be captured by the leaf against the inspection run / case object

#### Scenario: Advice request form renders via the forms leaf

- **GIVEN** a case and the OR forms leaf available
- **WHEN** a behandelaar starts an advice request ("Advies aanvragen")
- **THEN** the advice-request input form SHALL be rendered by the forms leaf

---

### Requirement: Inspection Photos Are Stored Through The OR Photos Leaf

Procest SHALL store and display inspection photos through OpenRegister's `photos` integration leaf
(files attached to the object). Procest SHALL NOT persist inline `photos[]` payloads inside
checklist items after this migration.

#### Scenario: Inspection photos attach via the photos leaf

- **GIVEN** a checklist item that captures a photo
- **WHEN** the inspector adds a photo
- **THEN** the photo SHALL be stored via the photos leaf as a file attached to the run / case object
- **AND** no inline `photos[]` payload SHALL be written into the checklist item

---

### Requirement: Inspection Domain Rules Stay In-App And Validate Leaf Data

Procest SHALL retain the checklist photo-gate rules (`fotoRequired: altijd | bij_nee | nooit`), the
checklist-run lifecycle, the append-only immutability enforcement
(`ChecklistRunImmutabilityListener`, REQ-IC-8), and the advice/consultation lifecycle in-app. These
rules SHALL validate the data captured by the forms/photos leaves rather than render it.

#### Scenario: Photo gate validates against photos-leaf attachments

- **GIVEN** a checklist item with `fotoRequired: altijd`
- **WHEN** the run is submitted with no photo attached via the photos leaf
- **THEN** `ChecklistService` SHALL reject the submission with `PHOTO_REQUIRED`
- **AND** the gate SHALL count attachments held by the photos leaf, not an inline payload

#### Scenario: Append-only immutability is preserved

- **GIVEN** a completed checklist run
- **WHEN** an edit to a finalized run is attempted
- **THEN** `ChecklistRunImmutabilityListener` SHALL block the mutation
- **AND** the immutability rule SHALL remain in procest, not the forms leaf

