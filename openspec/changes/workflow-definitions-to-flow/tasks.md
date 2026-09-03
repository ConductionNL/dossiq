# Tasks: workflow-definitions-to-flow

## Implementation Tasks

### Task 1: The migrator
- **spec_ref**: `openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md#requirement-req-wdf-001-a-definition-is-projected-onto-a-flow`
- **files**: `lib/Service/Workflow/WorkflowTemplateFlowMigrator.php`
- **acceptance_criteria**:
  - GIVEN a template WHEN projected THEN one `dossiq.setStatus` node per distinct status and one edge per transition
  - GIVEN JSON-encoded OR native-array transitions THEN both decode
  - GIVEN a wildcard `fromStatus` THEN that transition is skipped and the rest still project
  - GIVEN no usable transitions THEN the template is skipped, not projected empty
- [x] Implement
- [x] Test

### Task 2: Disabled, and named statuses
- **spec_ref**: `.../spec.md#requirement-req-wdf-002-the-projection-arrives-disabled` (+ REQ-WDF-003)
- **files**: `lib/Service/Workflow/WorkflowTemplateFlowMigrator.php`
- **acceptance_criteria**:
  - GIVEN a projected flow THEN `enabled` is false
  - GIVEN the nodes THEN each carries a status NAME, never a statusType id
- [x] Implement
- [x] Test — mutation-checked: creating the flow enabled turns the suite red

### Task 3: Idempotency and the command
- **spec_ref**: `.../spec.md#requirement-req-wdf-004-a-re-run-updates-rather-than-duplicating` (+ REQ-WDF-005)
- **files**: `lib/Command/MigrateWorkflowDefinitionsToFlowsCommand.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a second run THEN the existing flow is updated, resolved by its provenance marker and not by name
  - GIVEN `--dry-run` THEN nothing is written and the report still names the outcome
  - GIVEN a refused write THEN it is counted as failed, the rest still project, and the command exits non-zero
  - GIVEN no FlowService or no OpenRegister THEN the run reports why and writes nothing
- [x] Implement
- [x] Test

### Task 4: Retire workflowTemplate and collapse the two menu entries
- **spec_ref**: deferred
- **acceptance_criteria**:
  - Deliberately out of scope. The projection must be adopted and proven before the definition can go, and the definition still carries per-step SLAs, checklists and roles the projection does not. Removing the definitions page while it is still the only authoring surface would take away the way to edit a live workflow.
- [ ] Implement
- [ ] Test
