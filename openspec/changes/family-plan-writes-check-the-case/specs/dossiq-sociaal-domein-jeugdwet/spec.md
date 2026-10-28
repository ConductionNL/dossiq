# dossiq-sociaal-domein-jeugdwet Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [family-plan-writes-check-the-case](../../)

## ADDED Requirements

### Requirement: A write on a family plan needs the right to change its case

Saving a goal, closing a goal, saving an intervention and recording a review SHALL check, before anything is written,
that the caller may change the case the plan (or the goal) belongs to. A write that names an existing goal or
intervention by id SHALL also need the right to change that record's case. A plan without a case SHALL NOT be written.

#### Scenario: A caller without access to the case

- **GIVEN** a family plan of case A and a signed-in user who may not change case A
- **WHEN** they `POST /api/family-plans/{planId}/goals`, `/interventions` or `/reviews`, or close one of its goals
- **THEN** the answer is 403
- **AND** nothing is written

#### Scenario: Another family's goal named by id

- **GIVEN** a user who may change case A but not case B
- **WHEN** they save a goal on case A's plan with the `id` of a goal on case B's plan
- **THEN** the answer is 403 and case B's goal is unchanged

### Requirement: A family plan is read only by whoever may read its case

`GET /api/family-plans/{planId}` SHALL answer the plan only when the caller may read the case the plan points at, and
`GET /api/cases/{caseId}/family-plan` only when the caller may read that case, checked before any plan, goal or
intervention is read. A plan without a case SHALL NOT be answered.

#### Scenario: A caller without access to the case

- **GIVEN** a family plan of case A and a signed-in user who may not read case A
- **WHEN** they ask `/api/family-plans/{planId}` or `/api/cases/A/family-plan`
- **THEN** the answer is 403 and holds no plan, goal or intervention
