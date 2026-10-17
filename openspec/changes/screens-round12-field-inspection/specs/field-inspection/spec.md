## ADDED Requirements

### Requirement: An inspector's day, checklist and outbox are on one page (REQ-FI-1)

The manifest page `FieldInspection` (route `/field-inspection`, type
`dashboard`) SHALL show three device frames: today's `fieldInspection` records
for the reader, the reader's `inspectionChecklistRun` in execution, and the
`syncQueue` items with status pending, conflict or failed.

#### Scenario: A failed sync shows in the Synchronise frame
@e2e exclude measured against board DqInspectie by the round 12 pixel check, not an e2e flow

- **GIVEN** a `syncQueue` item with status failed
- **WHEN** you open the field inspection view
- **THEN** the Synchronise frame SHALL list it with its status
