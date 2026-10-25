## ADDED Requirements

### Requirement: One archive dialog per case

The case page SHALL open the same archive dialog from the closed stage's button and from the Lifecycle menu: the dialog of the `case-archive` action.

#### Scenario: archiving from the what-now card
@e2e exclude config pinned by tests/vitest/archiveStageOpensCaseArchive.spec.js; the dialog's behaviour is covered by tests/e2e archive-opens-its-dialog

- **GIVEN** a handler on a closed, ended case that is not archived
- **WHEN** they press Archive it in the what-now card
- **THEN** the dialog SHALL read "Close off · {case number}"
- **AND** it SHALL offer to let the requester know the case is completed
