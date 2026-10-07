## ADDED Requirements

### Requirement: A status may hold a limit and refuses work past it (REQ-STE-40)

`statusType` SHALL accept a `capacity`, a whole number of cases the status
may hold at once, where absent or zero means no limit. A transition into a
status at its capacity SHALL be refused with a status and a message naming
the status, the limit and the count found. The guard SHALL bind every
transition into the status, including transitions authored before the
capacity was set. A transition out of a full status SHALL never be refused
for capacity.

The count SHALL be of the cases whose `status` is that status, excluding the
case being moved. It is NOT additionally scoped to a case type, and it does not
filter final statuses: `statusType` carries its own `caseType`, so a status id
already names one lifecycle, and where a child case type inherits a parent's
status the cases of both types really are in one status, so one limit over both
is what "this status holds twelve" means. Scoping by case type as well would
let two types put twenty-four cases into a status whose limit reads twelve.

A FINAL status SHALL never be capped, whatever it declares. A case counted
towards a status's limit is by definition in that status, so the only way a
closed case reaches the count is when the status being entered is the closing
one; capping that would mean a case type could stop being able to close cases
after its twelfth.

A count the store cannot answer SHALL allow the move. A capacity is a planning
aid and not an authorization: refusing work because a read failed would stop a
desk over an outage, and the statutory term keeps running either way.

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
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a transition into the status defined before `capacity` was set on it
- **WHEN** it runs against a full status
- **THEN** it SHALL be refused

#### Scenario: the status type stores the limit
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status type given a capacity
- **WHEN** it is read back from Open Register
- **THEN** the number SHALL be there, because an unknown configuration key is dropped in silence

#### Scenario: the refusal is on the offered move
@e2e tests/e2e/status-capacity-limit.spec.ts

- **GIVEN** a status at its capacity
- **WHEN** the moves on offer for a case outside it are read
- **THEN** the move into that status SHALL still be offered, carrying `guardsPassed: false` and the sentence
- **AND** it SHALL NOT be hidden, because a move that vanishes reads as a broken workflow

#### Scenario: an unreadable count allows the move
@e2e exclude an outage cannot be staged on the e2e rig; covered by `tests/Unit/Service/Transitions/CapacityGuardTest.php::testAnUnreadableCountAllowsTheMove`

- **GIVEN** a store that cannot answer the count
- **WHEN** a case is moved into a capped status
- **THEN** the move SHALL be allowed

### Requirement: A bulk transition refuses per case, not per selection (REQ-STE-41)

A bulk transition into a status with fewer free places than selected cases
SHALL move as many as fit and SHALL report each refused case with the same
message. It SHALL NOT refuse the whole selection.

This needs no code of its own: `TransitionCasesAction::apply()` already takes ONE
object and answers `refused` with the first failed guard's message, so the
capacity guard fires per case and the cases that fit move.

#### Scenario: ten cases into three free places
@e2e exclude structural; the per-case shape is asserted in `tests/Unit/Service/Transitions/CapacityGuardWiringTest.php::testTheBulkActionAppliesPerCase`, and the guard that refuses each one in `CapacityGuardTest`

- **GIVEN** a status with `capacity: 12` holding nine cases, and ten cases selected
- **WHEN** the handler runs the bulk transition
- **THEN** three cases SHALL move
- **AND** seven SHALL be reported as refused, each naming the status and the limit

### Requirement: The board shows the count against the limit (REQ-STE-42)

A board column for a status carrying a capacity SHALL show the current count
against the limit. A drag into a full column SHALL be refused before the card
is placed, with the same message as the transition.

A column that MERGED more than one status type SHALL show no limit. The board
merges every non-final status type sharing a NAME, across every case type, and
a capacity is authored on one status type; one number over two different limits
would be wrong in both directions, and a header reading "9 of 12" beside an
engine that refuses at 4 is worse than no number, because the number is exactly
what a handler plans against. The refusal still bites per case, on the CONCRETE
status the case is moving into, which the board already resolves per case type.

The count the board shows is of what the board loaded, so the engine remains the
authority; the column number is the affordance and the refusal is the control.

#### Scenario: the column header carries the number
@e2e exclude the board fixture needs a seeded column per case type and the number is the board's own arithmetic over what it loaded; covered by `tests/vitest/statusCapacity.spec.js`, which asserts the merged-column case the requirement turns on

- **GIVEN** a status with `capacity: 12` holding nine cases
- **WHEN** a handler opens the board
- **THEN** that column SHALL show nine of twelve

#### Scenario: the card does not land in a full column
@e2e exclude a drag cannot be staged without the board fixture above; the refusal the drop uses is `capacityRefusal`, asserted in `tests/vitest/statusCapacity.spec.js`, and the engine's own refusal behind it in `tests/e2e/status-capacity-limit.spec.ts`

- **GIVEN** a full column
- **WHEN** a handler drags a card onto it
- **THEN** the card SHALL return to its own column
- **AND** the message SHALL name the status and the limit
