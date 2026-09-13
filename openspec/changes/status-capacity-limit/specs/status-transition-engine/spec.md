## ADDED Requirements

### Requirement: A status may hold a limit and refuses work past it (REQ-STE-40)

`statusType` SHALL accept a `capacity`, a whole number of cases the status
may hold at once, where absent or zero means no limit. A transition into a
status at its capacity SHALL be refused with a status and a message naming
the status, the limit and the count found. The guard SHALL bind every
transition into the status, including transitions authored before the
capacity was set. A transition out of a full status SHALL never be refused
for capacity. The count SHALL be of cases in that status within the same
case type, excluding cases in a final status.

#### Scenario: the case past the limit is refused
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status with `capacity: 3` holding three cases
- **WHEN** a handler moves a fourth case into it
- **THEN** the transition SHALL be refused
- **AND** the message SHALL name the status, the limit 3 and the count 3
- **AND** the fourth case SHALL still be in its previous status

#### Scenario: the case at the limit is allowed
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status with `capacity: 3` holding two cases
- **WHEN** a handler moves a third case into it
- **THEN** the transition SHALL succeed

#### Scenario: a status without a capacity is unchanged
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status with no `capacity`
- **WHEN** a hundredth case is moved into it
- **THEN** the transition SHALL succeed

#### Scenario: a full status can always be emptied
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status with `capacity: 3` holding three cases
- **WHEN** a handler moves one of them to the next status
- **THEN** the transition SHALL succeed
- **AND** a fourth case SHALL then be accepted

#### Scenario: a transition authored before the capacity is still bound

- **GIVEN** a transition into the status defined before `capacity` was set on it
- **WHEN** it runs against a full status
- **THEN** it SHALL be refused
- **AND** a closed case in a final status SHALL NOT count towards the limit

### Requirement: A bulk transition refuses per case, not per selection (REQ-STE-41)

A bulk transition into a status with fewer free places than selected cases
SHALL move as many as fit and SHALL report each refused case with the same
message. It SHALL NOT refuse the whole selection.

#### Scenario: ten cases into three free places
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status with `capacity: 12` holding nine cases, and ten cases selected
- **WHEN** the handler runs the bulk transition
- **THEN** three cases SHALL move
- **AND** seven SHALL be reported as refused, each naming the status and the limit

### Requirement: The board shows the count against the limit (REQ-STE-42)

A board column and a status chip for a status carrying a capacity SHALL
show the current count against the limit. A drag into a full column SHALL
be refused before the card is placed, with the same message as the
transition.

#### Scenario: the column header carries the number
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status with `capacity: 12` holding nine cases
- **WHEN** a handler opens the board
- **THEN** that column SHALL show nine of twelve

#### Scenario: the card does not land in a full column
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a full column
- **WHEN** a handler drags a card onto it
- **THEN** the card SHALL return to its own column
- **AND** the message SHALL name the status and the limit
