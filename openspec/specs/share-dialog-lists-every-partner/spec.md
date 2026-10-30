# share-dialog-lists-every-partner Specification

## Purpose
What dossiq does for share dialog lists every partner. The requirements were first written by the change `share-dialog-lists-every-partner` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-share-dialog-lists-every-partner/`) and cover: The share dialog offers every partner organisation.

## Requirements

### Requirement: The share dialog offers every partner organisation

The case share dialog and the sharing tab SHALL offer every organisation of type `partner` on the instance, whether or not the user is a member of it, and SHALL NOT offer the instance's own organisations.

#### Scenario: a partner the user is not a member of
- GIVEN a partner organisation "Omgevingsdienst Zuid-Holland Zuid" and a user who is not its member
- WHEN the user opens "Delen met een andere organisatie"
- THEN the organisation select offers it
