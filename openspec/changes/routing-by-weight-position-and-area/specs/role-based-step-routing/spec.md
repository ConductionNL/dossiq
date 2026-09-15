## ADDED Requirements

### Requirement: A pool member carries a weight the strategies read (REQ-RTP-01)

A membership of a routing pool SHALL carry a weight, defaulting to one.
Round robin and least loaded SHALL divide work in proportion to the weights.
A pool whose weights are all one SHALL be routed exactly as it is today.

#### Scenario: A part-time member gets a smaller share
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** a pool of one member at weight 1 and one at weight 0.4
- **WHEN** fourteen cases are routed by round robin
- **THEN** the first member SHALL receive ten and the second four

#### Scenario: An unweighted pool is unchanged
@e2e exclude unit, behaviour parity; WeightedRoundRobinTest

- **GIVEN** a pool whose members all carry weight 1
- **WHEN** cases are routed by either strategy
- **THEN** the result SHALL be identical to the result before this change

#### Scenario: Least loaded reads the weight as capacity
@e2e exclude unit; WeightedLeastLoadedTest

- **GIVEN** a member at weight 2 holding six cases and a member at weight 1 holding four
- **WHEN** the next case is routed by least loaded
- **THEN** it SHALL go to the member at weight 2

### Requirement: A rule may target a position inside a team (REQ-RTP-02)

A routing rule SHALL be able to name a position within a named team, such as
the senior handler of one team, resolved from the organisation. Naming a
position SHALL NOT require an organisation-wide role for it.

#### Scenario: The senior of one team, not of all teams
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** two teams, each with a senior handler
- **WHEN** a case is routed to the senior handler of team zuid
- **THEN** it SHALL reach that person
- **AND** the senior handler of the other team SHALL not be a candidate

### Requirement: Work not taken up returns to the pool (REQ-RTP-03)

A pool SHALL be able to declare a window within which an assignment must be
accepted or started. When the window passes, the work SHALL return to the
pool and be routed on by the same strategy. The return SHALL record who held
it, why it came back and who holds it now.

#### Scenario: An unaccepted case goes back
@e2e exclude time-dependent; unit over the timer-fired listener, TakeBackTest

- **GIVEN** a case routed to a member of a pool with a two-day window
- **WHEN** two working days pass without acceptance
- **THEN** the case SHALL return to the pool and be routed to another member
- **AND** the case SHALL record the first member, the reason and the new holder

#### Scenario: Accepting cancels the window
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** the same case
- **WHEN** the member accepts it the next morning
- **THEN** it SHALL stay with them
- **AND** no take-back SHALL be recorded

### Requirement: The case holds the area it is in, and routing reads it (REQ-RTP-04)

Setting or changing a case's address SHALL resolve its district,
neighbourhood and area from the administered boundaries, and those values
SHALL be held on the case. A routing rule SHALL be able to read them, and
`districtTeam` SHALL be resolved from them. The boundary set SHALL carry its
source and the date it was administered.

#### Scenario: The area team gets the case
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** a boundary set in which an address falls in wijk Zuid
- **WHEN** a case is created at that address
- **THEN** the case SHALL hold that wijk
- **AND** the case SHALL route to the team declared for it

#### Scenario: Changing the address re-resolves the area
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** a case in wijk Zuid
- **WHEN** its address is corrected to an address in wijk Noord
- **THEN** the held area SHALL be Noord

#### Scenario: An address outside every boundary uses the fallback
@e2e exclude unit; CaseAreaResolutionTest

- **GIVEN** an address that falls in no administered boundary
- **WHEN** the case is routed
- **THEN** it SHALL route by the fallback the case type declares
- **AND** the case SHALL say that the fallback was used

#### Scenario: The area can be explained
@e2e exclude unit; CaseAreaResolutionTest

- **GIVEN** a case whose area was resolved last year
- **WHEN** the resolution is read
- **THEN** it SHALL name the boundary set and the date it was administered
