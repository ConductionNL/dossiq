## ADDED Requirements

### Requirement: A transition declares what must be settled first (REQ-TRD-01)

A transition SHALL be able to declare the dependencies that must be settled
before it is available. While one is open the transition SHALL be withheld
rather than offered and refused, and the reason SHALL be readable where the
transition would have been. Every closing status SHALL be withheld by an
open dependency, not only the status the case type names as closed.

#### Scenario: A case with an open advice request cannot be closed
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** a case with an open advice request
- **WHEN** a handler opens the transition list
- **THEN** no closing status SHALL be offered
- **AND** the list SHALL say that the advice request is open

#### Scenario: Settling the dependency restores the transition
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** the same case
- **WHEN** the advice is received and the request is settled
- **THEN** the closing statuses SHALL be offered again

#### Scenario: A second kind of dependency needs no new service
@e2e exclude unit over two declared dependencies; TransitionPreconditionTest

- **GIVEN** a case type declaring an unpaid fee as a dependency of closing
- **WHEN** the fee is unpaid
- **THEN** the closing transitions SHALL be withheld by the same mechanism as the advice request

### Requirement: A transition and a status carry an explanation (REQ-TRD-02)

An administrator SHALL be able to write an explanation on a status and on a
transition. The status explanation SHALL be rendered on the case. The
transition explanation SHALL be rendered in the list the handler chooses
from. An empty explanation SHALL render nothing rather than an empty space.

#### Scenario: The handler reads the guidance while choosing
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** a transition with an explanation written by an administrator
- **WHEN** a handler opens the transition list
- **THEN** the explanation SHALL be readable beside the transition

#### Scenario: The status explanation reaches the case
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** a status whose `description` an administrator has written
- **WHEN** a case in that status is opened
- **THEN** the description SHALL be rendered on the case

### Requirement: A transition may be closed to whoever performed an earlier act (REQ-TRD-03)

A transition SHALL be able to declare an earlier act whose performer may not
make it. The engine SHALL read who performed that act on this case and
SHALL refuse the transition for that person. The refusal SHALL name the act,
its date and the person, and the case type SHALL be able to name who may be
asked instead.

#### Scenario: The author of a decision may not approve it
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** a case whose draft decision was written by a handler
- **WHEN** that handler tries to approve it
- **THEN** the transition SHALL be refused
- **AND** the refusal SHALL name the act and its date

#### Scenario: A colleague may approve it
@e2e tests/e2e/what-a-transition-declares.spec.ts

- **GIVEN** the same case
- **WHEN** a colleague with the right mandate approves it
- **THEN** the transition SHALL proceed

#### Scenario: The same person may approve a case they did not prepare
@e2e exclude unit; FourEyesTransitionTest

- **GIVEN** a second case the same handler did not prepare
- **WHEN** they approve it
- **THEN** the transition SHALL proceed
- **AND** the rule SHALL have read the act, not the role
