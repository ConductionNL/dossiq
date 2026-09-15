## ADDED Requirements

### Requirement: A pause names a reason that chases (REQ-TERM-011)

A `pauseReason` per case type SHALL declare name, legal basis, chase
interval in days, chase text, chase budget and whether the interval counts
working days. The pause dialog SHALL pick a reason; the free-text rationale
SHALL remain as a note. The helper timer armed for the pause SHALL carry one
escalation rung per chase inside the budget, and each fire SHALL send the
chase text to the requester and record a `deadlineEvent` `chased`.

#### Scenario: The applicant is chased on schedule
@e2e exclude time-dependent; covered by a TermijnTimerService fixture pair (rung offsets for a 14-day pause, 5-day interval, budget 2) and a TermijnTimerFiredListener unit test over the messaging stub

- **GIVEN** a case paused for 14 days with reason "Aanvulling gevraagd", interval 5, budget 2
- **WHEN** the first chase rung fires
- **THEN** the chase text SHALL be sent to the requester
- **AND** a `chased` event SHALL be recorded on the instance

#### Scenario: The handler hears after the last chase
@e2e exclude covered by the same listener unit test, last-rung branch

- **GIVEN** the same pause after two chases without a reply
- **WHEN** the second chase rung fires
- **THEN** the handler SHALL be notified that no reply came after 2 reminders

#### Scenario: Resume stops the chasing
@e2e tests/e2e/pause-reason-with-chasing.spec.ts

- **GIVEN** a paused case with chases pending
- **WHEN** you record the aanvulling and resume
- **THEN** no further chase SHALL be sent
- **AND** the instance SHALL show the reason and the chases sent

### Requirement: The pause dialog offers the case type's reasons (REQ-TERM-012)

The pause dialog on `#CaseDetail` SHALL list the `pauseReason` rows of the
case's type and SHALL require one.

#### Scenario: Pick a reason
@e2e tests/e2e/pause-reason-with-chasing.spec.ts

- **GIVEN** a case type with two pause reasons
- **WHEN** you press Suspend
- **THEN** the dialog SHALL list both and SHALL refuse to save without one
