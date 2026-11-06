## ADDED Requirements

### Requirement: The case type flow page loads its workflow when opened directly

The page `/settings/case-types/:id/flow` MUST wait until the app's object stores are registered before it reads the case
type's workflow versions, so that a direct load shows the same workflow as a visit through "View as flow".

#### Scenario: Opening the flow page directly
- GIVEN a case type with a published workflow
- WHEN a user opens `/settings/case-types/{id}/flow` directly
- THEN the page shows a loading state until the stores are up
- AND then draws the workflow editor for that case type, not "No workflow defined"
