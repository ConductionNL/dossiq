# dashboard Delta: r7-dossiq-filtered-title-board-config

## ADDED Requirements

### Requirement: The workflow board page config declares no half register/schema pair

No custom page in the manifest or a structure profile SHALL declare only one
of `config.register` and `config.schema`.

> The WorkflowBoard custom page this was written for has retired: the workflow
> board is the Cases list in board view, an index page that declares both. The
> rule for every other custom page stands.

#### Scenario: Opening the workflow board
<!-- @e2e exclude Manifest shape; proven by tests/vitest/customPageTypesConfig.spec.js and live on :8099 (console without the CnPageRenderer warning). -->
- **GIVEN** a user opens the workflow board
- **WHEN** the page renders
- **THEN** the console holds no `declares only one of config.register / config.schema` warning
