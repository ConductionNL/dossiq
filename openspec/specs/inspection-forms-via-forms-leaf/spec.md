# inspection-forms-via-forms-leaf Specification

## Purpose
TBD - created by archiving change migrate-inspection-forms-to-forms-leaf. Update Purpose after archive.
## Requirements
### Requirement: Checklist And Advice Forms Render Through The OR Forms Leaf

Procest SHALL render inspection checklist items and advice/consultation request forms through
OpenRegister's `forms` integration leaf (ADR-019). Procest SHALL NOT hand-render form question
inputs in `InspectionChecklistPanel.vue` / `DocumentChecklist.vue` after this migration.

@e2e exclude Form rendering is owned by OpenRegister's `forms` integration leaf (cross-app, ADR-019); the leaf tab surfaces via `@conduction/nextcloud-vue`'s builtin integration registry and fetches from the OR integrations endpoint. Without the OR forms leaf installed it cannot be exercised by a procest-only UI e2e. The procest-side change is the removal of hand-rendered inputs from `InspectionChecklistPanel.vue` / `DocumentChecklist.vue` (a static no-parallel-rendering check) plus the registry/manifest tab wiring (gate-22 manifest validation), not a procest UI surface.

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

@e2e exclude Photo storage/display is owned by OpenRegister's `photos` integration leaf (cross-app, ADR-019) — files are attached to the object via the OR integrations endpoint and surfaced by the leaf's builtin Vue tab. Without the OR photos leaf installed this has no procest UI surface to drive in a procest-only e2e. The procest-side change is the removal of inline `photos[]` persistence (a static no-parallel-storage check), covered by PHPUnit.

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

@e2e exclude Photo-gate enforcement (`ChecklistService` `PHOTO_REQUIRED`) and append-only immutability (`ChecklistRunImmutabilityListener`, REQ-IC-8) are backend service logic that validate leaf-captured data — covered by PHPUnit (`fieldInspectionHelpers`/`ChecklistService` + immutability-listener tests), not a procest UI surface. The reject/block behaviour cannot be asserted in a browser without the OR photos leaf supplying real attachment counts.

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

