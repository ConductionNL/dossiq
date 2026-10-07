## ADDED Requirements

### Requirement: An obligation is one declared mechanism (REQ-OBL-01)

An obligation placed on another person or unit SHALL be one declared thing
with three parts: what is placed and on whom, what settles it, and what it
blocks while it is open. Meeting it SHALL release what it blocked. A new
kind of obligation SHALL be a declaration, not a new service. The advice
request SHALL be the first obligation of this kind and SHALL keep its
current behaviour.

#### Scenario: An obligation blocks and then releases
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** a case type declaring an inspection as an obligation that blocks closing
- **WHEN** the inspection is requested
- **THEN** a task SHALL reach the inspecting unit
- **AND** the closing transitions SHALL be withheld
- **AND** recording the inspection SHALL release them

#### Scenario: The advice request behaves as it does today
@e2e exclude unit, behaviour parity over the existing service; ConsultationServiceTest

- **GIVEN** a case with an advice request
- **WHEN** the request is placed, chased and answered
- **THEN** the case SHALL block and release exactly as it does today
- **AND** the behaviour SHALL come from the declared obligation

#### Scenario: An obligation with a term of its own
@e2e exclude time-dependent; unit over the timer-fired listener, ObligationReleaseTest

- **GIVEN** an obligation declaring a term of ten working days
- **WHEN** the term passes unmet
- **THEN** the obligation SHALL be reported as overdue
- **AND** the case SHALL stay blocked until it is settled or withdrawn

#### Scenario: Withdrawing an obligation releases the case and says so
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** an open obligation a handler withdraws with a reason
- **WHEN** the withdrawal is recorded
- **THEN** the case SHALL be released
- **AND** the withdrawal and its reason SHALL stay readable on the case
