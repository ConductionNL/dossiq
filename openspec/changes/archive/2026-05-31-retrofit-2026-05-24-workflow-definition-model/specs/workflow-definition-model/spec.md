---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

# Workflow Definition Model — implementation surface (retrofit)

## Requirements

### REQ-001: WorkflowDefinitionController SHALL expose lifecycle + lookup endpoints

`OCA\Procest\Controller\WorkflowDefinitionController` SHALL provide HTTP endpoints for: `publish($id)` (move draft → active), `deprecate($id)` (active → deprecated), `cloneDefinition($id)` (create new draft from existing), `active($caseTypeId)` (lookup currently-active version for a case type), and `forCase($caseId)` (lookup version bound to a specific case). Each endpoint SHALL delegate to `WorkflowDefinitionService` and SHALL reject lifecycle transitions that violate the draft → active → deprecated state machine.

#### Scenario: Publish a draft
- **WHEN** `POST /api/workflow-definitions/{id}/publish` is called on a draft definition
- **THEN** the definition's status SHALL flip to active and any previously-active version for the same case type SHALL be auto-deprecated

### REQ-002: WorkflowDefinitionService SHALL implement the full lifecycle + version selection

`OCA\Procest\Service\WorkflowDefinitionService` SHALL provide the canonical version-selection logic (`getActiveDefinitionFor($caseTypeId)`, `getDefinitionForCase($caseId)`, `listVersions($caseTypeId)`) and the full lifecycle (`createDraft`, `publish`, `deprecate`, `cloneDefinition`, `getDefinition`). Version selection SHALL be deterministic: at most one active version per case type at any time; a case bound to a specific version SHALL continue to use that version even after a newer one is published (versions, not branches).

#### Scenario: Existing case keeps its bound version after a new one is published
- **GIVEN** case C bound to workflow version v1
- **WHEN** v2 is published for the same case type
- **THEN** `getDefinitionForCase(C.id)` SHALL still return v1 and only newly-created cases SHALL be bound to v2

### REQ-003: MigrateWorkflowDefinitions SHALL be a one-shot repair step for legacy data

`OCA\Procest\Repair\MigrateWorkflowDefinitions` SHALL run as a Nextcloud repair step that detects legacy inline workflow definitions on case-type records and lifts them into stand-alone workflow definition entities. The repair step SHALL be idempotent: on a fully-migrated dataset it SHALL be a no-op, and re-running it SHALL NOT duplicate definitions.

#### Scenario: Idempotent re-run
- **GIVEN** a procest instance where MigrateWorkflowDefinitions has already run
- **WHEN** `MigrateWorkflowDefinitions::run($output)` runs again
- **THEN** no new workflow definitions SHALL be created and the output SHALL log the no-op

Notes
- Once every active deployment is past the migration window, this repair step is a candidate for removal in a future cleanup spec.
