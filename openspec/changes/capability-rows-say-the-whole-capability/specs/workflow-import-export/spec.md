# workflow-import-export

## ADDED Requirements

### Requirement: A BPMN process file can be imported as a flow (REQ-CRS-10)

An administrator SHALL be able to import a BPMN process file and get a flow they can open in the flow editor. Every element the import could not carry over SHALL be reported by name.

Rows: `11.5` (dossiq matrix).

#### Scenario: A process with an unsupported element
- **GIVEN** a BPMN file with tasks, gateways and one unsupported element
- **WHEN** an administrator imports it
- **THEN** a flow opens in the editor and the report names the unsupported element
