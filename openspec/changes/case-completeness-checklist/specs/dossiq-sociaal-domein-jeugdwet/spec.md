# dossiq-sociaal-domein-jeugdwet Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-completeness-checklist](../../)

## ADDED Requirements

### Requirement: The family plan answer names its plan

`GET /api/cases/{caseId}/family-plan` and `GET /api/family-plans/{planId}` SHALL answer `planId` at the top level,
so a manifest URL token can address the plan. Without a plan, `planId` SHALL be `null`.

#### Scenario: Doel toevoegen

- **GIVEN** a case whose gezinsplan has id `plan-new`
- **THEN** the family plan answer carries `planId: "plan-new"`
- **AND** Doel toevoegen posts to `/api/family-plans/plan-new/goals`
