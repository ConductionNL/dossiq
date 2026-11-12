# role-routing-via-or-rbac

## ADDED Requirements

### Requirement: An administrator edits the role matrix as a grid (REQ-CRS-15)

An administrator SHALL be able to edit, in one grid, which role may do which act on which object type. A changed cell SHALL take effect for the next request of every user holding that role.

Rows: `13.16` (dossiq matrix).

#### Scenario: A cell is switched off
- **GIVEN** the grid allowing handlers to delete documents
- **WHEN** an administrator switches that cell off and saves
- **THEN** a handler's next delete of a document is refused
