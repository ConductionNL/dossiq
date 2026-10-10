# case-linked-projects Specification

## Purpose
A case shows the planninq projects linked to it and lets a handler start a new project from the case. Planninq owns projects and registers the leaf; dossiq only declares it on the case, so the panel is absent on an instance without planninq.

## Requirements

### Requirement: The case page shows the projects planninq links to the case (REQ-CLP-001)
`case.linkedTypes` MUST include `planninq-projects`, and the case page MUST
place planninq's projects leaf for the case. On an instance without planninq
the panel MUST be absent rather than empty.

#### Scenario: A handler starts a project from a case
- **GIVEN** a handler on the page of case "Herinrichting Dorpsstraat" on an instance with planninq
- **WHEN** they open the Projects panel and choose "New project"
- **THEN** planninq's New project dialog MUST open with that case linked and its title filled in
- **AND** after saving, the Projects panel on the case MUST list the new project

#### Scenario: No planninq, no panel
- **GIVEN** an instance without planninq
- **WHEN** a handler opens any case page
- **THEN** there MUST be no Projects panel
