## ADDED Requirements

### Requirement: A bulk move carries each case's failed actions

Bulk execution SHALL keep the per-case status `succeeded` for a case that
moved, and SHALL carry that case's `failedActions` from the engine beside it,
an empty list when every action ran. It SHALL NOT add a per-case status value
for a partial move, and the summary counts SHALL NOT change.

The bulk dialog SHALL say how many of the moved cases did not get all of their
automatic actions.

#### Scenario: One case moved without its actions
@e2e exclude a failing action cannot be provoked from the browser on a healthy instance; covered by BulkStatusTransitionServiceTest and bulkTransitionDialog.spec.js

- **GIVEN** two cases, where the engine answers `partial` for the second with one failed action
- **WHEN** bulk execute runs
- **THEN** both cases SHALL report `succeeded`
- **AND** the second case SHALL carry that failed action in `failedActions`
- **AND** the first SHALL carry an empty `failedActions`
- **AND** the summary SHALL count two succeeded
- **AND** the dialog SHALL say one case moved without all of its automatic actions
