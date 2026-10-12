## MODIFIED Requirements

### Requirement: Checklist And Advice Forms Render Through The OR Forms Leaf

Dossiq SHALL render advice and consultation request forms through OpenRegister's `forms` integration leaf (ADR-019). Inspection checklists SHALL NOT render through the forms leaf: a leaf form writes fields of the subject object, and a case has no property per checklist question. A checklist run is an OpenRegister task whose answers sit in `Task.responses` (change inspection-checklists-onto-task, decision 175).

@e2e exclude Form rendering is owned by OpenRegister's `forms` integration leaf (cross-app, ADR-019); without the OR forms leaf installed it cannot be exercised by a dossiq-only UI e2e.

#### Scenario: Checklist items render via the forms leaf

The scenario keeps its name for the archive; what it now says is that checklist items do NOT render through the leaf.

- **GIVEN** an inspection checklist template on a case
- **WHEN** an inspector completes the checklist
- **THEN** the answers SHALL be written as the responses of the case's inspection task
- **AND** no forms-leaf submission SHALL be made for the checklist

#### Scenario: Advice request form renders via the forms leaf

- **GIVEN** a case and the OR forms leaf available
- **WHEN** a behandelaar starts an advice request ("Advies aanvragen")
- **THEN** the advice-request input form SHALL be rendered by the forms leaf

### Requirement: Inspection Photos Are Stored Through The OR Photos Leaf

Dossiq SHALL store and display inspection photos as files attached to the case object through OpenRegister's `photos` integration leaf. The inspection task SHALL list their file ids as its `evidence`, and each answer SHALL name its photos by file id. Dossiq SHALL NOT persist photo bytes inside an answer.

@e2e exclude Photo storage is owned by OpenRegister's `photos` integration leaf (cross-app, ADR-019); without the leaf installed this has no dossiq UI surface to drive.

#### Scenario: Inspection photos attach via the photos leaf

- **GIVEN** a checklist item that captures a photo
- **WHEN** the inspector adds a photo
- **THEN** the photo SHALL be stored as a file attached to the case object
- **AND** the inspection task SHALL list the file id as evidence, and the answer SHALL name it

### Requirement: Inspection Domain Rules Stay In-App And Validate Leaf Data

Dossiq SHALL retain the checklist photo-gate rules (`photoRequired: altijd | if_no | nooit`) and the advice/consultation lifecycle in-app, and SHALL check a run against its frozen template before the inspection task is completed. Append-only immutability after submit SHALL come from OpenRegister: a completed task is terminal.

@e2e exclude Photo-gate enforcement (`ChecklistService`) and task terminality are backend rules covered by PHPUnit, not a dossiq UI surface.

#### Scenario: Photo gate validates against photos-leaf attachments

- **GIVEN** a checklist item with `photoRequired: altijd`
- **WHEN** the run is submitted with no photo on that answer
- **THEN** `ChecklistService` SHALL refuse the run
- **AND** no inspection task SHALL be created or completed

#### Scenario: Append-only immutability is preserved

- **GIVEN** a completed inspection task
- **WHEN** an edit to it is attempted
- **THEN** OpenRegister SHALL refuse the change, because a completed task is terminal
