## ADDED Requirements

### Requirement: Publishing MUST refuse a move nothing can fire

The system MUST refuse to publish a case type whose active workflow
template holds a move that no reader can ever offer, and the refusal MUST
name that move.

A move cannot be offered when its `fromStatus` is empty, is the `*`
wildcard, or names a status this case type does not declare. Both readers
of a transition compare `fromStatus` to the status the case is in with a
strict equality, so all three spellings are moves that are authored,
stored and never offered.

A move whose `toStatus` names a status this case type does not declare
MUST be refused the same way.

#### Scenario: a wildcard move is refused by name
@e2e tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts

- **GIVEN** a draft case type whose template holds a move with `fromStatus` `*`
- **WHEN** an administrator publishes it
- **THEN** publication SHALL be refused
- **AND** the finding SHALL name that move
- **AND** the case type SHALL stay a draft

#### Scenario: a move from a status of another case type is refused by name
@e2e tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts

- **GIVEN** a draft case type whose template holds a move starting from a status it does not declare
- **WHEN** an administrator publishes it
- **THEN** the finding SHALL name that move
- **AND** SHALL say it starts from a status this case type does not have

#### Scenario: a sound lifecycle publishes
@e2e tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts

- **GIVEN** a draft case type whose moves run from its initial status to a final one
- **WHEN** an administrator publishes it
- **THEN** publication SHALL succeed
- **AND** no reachability finding SHALL be raised

### Requirement: Publishing MUST refuse a status nothing leads to

The system MUST refuse to publish a case type declaring a status that no
sound move leads to, and the refusal MUST name that status.

The status a new case starts in is exempt, because that is where a case
begins rather than somewhere it must be led to.

A status unreachable only because a move already reported under the
previous requirement is broken MUST NOT be reported a second time. One
root cause produces one finding.

#### Scenario: an orphaned status is refused by name
@e2e tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts

- **GIVEN** a draft case type declaring three statuses and one move that skips the middle one
- **WHEN** an administrator publishes it
- **THEN** publication SHALL be refused
- **AND** the finding SHALL name the status nothing leads to

#### Scenario: one broken move produces one finding
@e2e tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts

- **GIVEN** a draft case type whose only way into a status is a move nothing can fire
- **WHEN** an administrator publishes it
- **THEN** exactly one finding SHALL be raised
- **AND** it SHALL name the move, not the status

### Requirement: Publishing MUST refuse a lifecycle with no way to close

The system MUST refuse to publish a case type when no sequence of moves
leads from the status a new case starts in to a status that closes a case.
The refusal MUST name the status a new case starts in.

#### Scenario: a lifecycle that can never close is refused
@e2e tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts

- **GIVEN** a draft case type whose moves loop between two open statuses
- **AND** a final status no move leads to
- **WHEN** an administrator publishes it
- **THEN** publication SHALL be refused
- **AND** a finding SHALL say a case of this type could never be finished

### Requirement: A case type with no moves MUST publish unchanged

The system MUST raise no reachability finding for a case type whose active
workflow template is absent or holds no moves. Those case types drive
their lifecycle from statuses alone and are the majority.

#### Scenario: a case type with no workflow template still publishes
@e2e tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts

- **GIVEN** a draft case type with statuses and no workflow template
- **WHEN** an administrator publishes it
- **THEN** publication SHALL succeed

### Requirement: The reachability walk SHALL be unit-tested

The system SHALL pin the walk with unit tests pairing every refusal with
an acceptance, so a guard that refused every case type could not pass.

#### Scenario: unit tests cover every refusal and its control
@e2e exclude Unit tests are the assertion; an e2e run would only restate them.

- **GIVEN** the `CaseTypeReachabilityTest` suite
- **WHEN** `composer test` runs
- **THEN** it SHALL cover the wildcard move, the empty `fromStatus`, the
  foreign `fromStatus`, the foreign `toStatus`, the orphaned status, the
  de-duplication, the lifecycle that cannot close, the case type with no
  moves, and a sound lifecycle that produces nothing
