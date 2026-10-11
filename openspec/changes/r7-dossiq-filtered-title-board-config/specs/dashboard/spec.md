# dashboard Delta: r7-dossiq-filtered-title-board-config

## ADDED Requirements

### Requirement: The workflow board page config declares no half register/schema pair

The WorkflowBoard custom page SHALL declare neither `config.register` nor
`config.schema`, because it loads statusType, caseType and case through the
object types the app registers itself. No custom page in the manifest or a
structure profile SHALL declare only one of the two.

#### Scenario: Opening the workflow board
<!-- @e2e exclude Manifest shape; proven by tests/vitest/customPageTypesConfig.spec.js and live on :8099 (console without the CnPageRenderer warning). -->
- **GIVEN** a user opens the Workflow board
- **WHEN** the page renders
- **THEN** the console holds no `declares only one of config.register / config.schema` warning
- **AND** the board shows the same columns and cases as before
