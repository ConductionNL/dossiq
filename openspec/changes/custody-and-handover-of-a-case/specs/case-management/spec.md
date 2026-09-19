## ADDED Requirements

### Requirement: Case ownership is a dated chain of holdings (REQ-CUS-01)

A case SHALL carry a `caseCustody` record for every period it was held. Each
holding SHALL name the organisation unit, the handler where there is one,
the moment it began, the moment it ended, the reason for the move and who
made it. A case SHALL have exactly one open holding at any moment.

#### Scenario: A transfer closes one holding and opens the next
@e2e tests/e2e/custody-and-handover-of-a-case.spec.ts

- **GIVEN** a case held by unit A since 3 March
- **WHEN** it is transferred to unit B on 12 April
- **THEN** the holding by unit A SHALL be closed on 12 April
- **AND** an open holding by unit B SHALL begin on the same moment
- **AND** the reason and the person who moved it SHALL be recorded on the new holding

#### Scenario: Who held the case in March is a query
@e2e exclude unit over the reader; CaseCustodyQueryTest

- **GIVEN** a case transferred three times in a year
- **WHEN** the custody is read for 15 March
- **THEN** exactly one unit SHALL be returned
- **AND** the answer SHALL come from the custody record, not from the audit diff

#### Scenario: A case is never held by nobody
@e2e exclude unit; CaseCustodyChainTest

- **GIVEN** any case in the system
- **WHEN** its holdings are read
- **THEN** exactly one SHALL be open
- **AND** the holdings SHALL cover the case's life without a gap

### Requirement: A colleague may ask the holder for a case (REQ-CUS-02)

A user SHALL be able to request a case from the person or unit holding it,
with a reason. The request SHALL reach the holder as a task. The holder
SHALL accept or refuse, and a refusal SHALL carry a reason. Both answers
SHALL be recorded on the case.

#### Scenario: The holder refuses with a reason
@e2e tests/e2e/custody-and-handover-of-a-case.spec.ts

- **GIVEN** a case held by a handler
- **WHEN** a colleague requests it and the holder refuses, giving a reason
- **THEN** the case SHALL stay with the holder
- **AND** the refusal and its reason SHALL be readable on the case

#### Scenario: Accepting moves the case and writes the next holding
@e2e tests/e2e/custody-and-handover-of-a-case.spec.ts

- **GIVEN** the same request
- **WHEN** the holder accepts
- **THEN** the case SHALL move to the asker through the ordinary transfer path
- **AND** a new holding SHALL open naming the asker

#### Scenario: An unanswered request escalates to the unit
@e2e exclude time-dependent; unit over the timer-fired listener, CaseTakeoverEscalationTest

- **GIVEN** a takeover request older than the period the case type declares
- **WHEN** the period passes with no answer
- **THEN** the request SHALL go to the unit holding the case
- **AND** it SHALL NOT expire unanswered
