## ADDED Requirements

### Requirement: You start an allowed flow from the case (REQ-WDE-01)

You start an allowed sub-process for the case from its page. A case type SHALL
list the flows a handler may start in `startableFlows`. The Actions menu of
`CaseDetail` SHALL offer Start, listing those flows by title. Running one
SHALL post the case as the flow's subject, and the run SHALL appear in the
case's flow runs.

**Feature tier**: V1

#### Scenario: The Start list follows the case type
@e2e tests/e2e/case-actions-menu.spec.ts

- **GIVEN** a case type whose `startableFlows` names the flow Create sub-case
- **AND** a case of that type
- **WHEN** the handler opens Start on the case page
- **THEN** the list SHALL show Create sub-case
- **AND** SHALL NOT show flows the type does not list

#### Scenario: A run appears on the case
@e2e exclude running a flow needs an ADOPTED, published and enabled flow, and a fresh install deliberately has none: OpenRegister stores a shipped flow ownerless and disabled, and adoption is an administrator's separate act, so arranging one would make this a test of the adoption path instead

- **GIVEN** the same case
- **WHEN** the handler runs Create sub-case
- **THEN** the Flow runs widget SHALL list one new run with this case as subject

#### Scenario: A type without startable flows hides Start
@e2e tests/e2e/case-actions-menu.spec.ts

- **GIVEN** a case type with an empty `startableFlows`
- **WHEN** the handler opens the Actions menu on a case of that type
- **THEN** Start SHALL NOT be offered

### Requirement: You plan a follow-up case (REQ-WDE-02)

You plan a follow-up case for a later date. It appears when it is due. The
Actions menu and the Related cases tab of `CaseDetail` SHALL offer Plan
follow-up, asking for a case type, a date and a title. Confirming SHALL write
one scheduled flow that creates the case on that date, related to this one,
running as the person who planned it. Until it fires, the Related cases tab
SHALL show the planned case with its date.

**Feature tier**: V1

#### Scenario: A planned case shows on the tab
@e2e tests/e2e/case-actions-menu.spec.ts

- **GIVEN** an open case
- **WHEN** the handler plans a follow-up of type Controle for a date next month
- **THEN** the Related cases tab SHALL show a planned row with that type and date
- **AND** no case of type Controle SHALL exist yet

#### Scenario: The follow-up is created when due
@e2e exclude the schedule trigger fires from OpenRegister's timer; PHPUnit covers the flow the controller writes, including `runAs`

- **GIVEN** a planned follow-up whose date has arrived
- **WHEN** OpenRegister runs the scheduled flow
- **THEN** a case of the planned type SHALL exist with this case under related cases
- **AND** the planned row SHALL be gone from the Related cases tab
