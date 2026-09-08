## ADDED Requirements

### Requirement: Lenses on the Cases index [V1]

You switch between your cases, unclaimed cases and all cases on one list.
The `Cases` page (`src/manifest.json`, type `index` over `case`) SHALL
carry `quickFilters` chips in this order: Mine (`assignee = @me`,
`isFinalStatus = false`), Unclaimed (`assignee = "IS NULL"`,
`isFinalStatus = false`), All (no filter), Closed (`isFinalStatus = true`)
and Overdue (`deadline lt @today`, `isFinalStatus = false`). Mine SHALL be
the default chip. Exactly one chip is active at a time and choosing a chip
SHALL replace the previous chip's filter, not stack on it. The Unclaimed
chip SHALL use the same filter as the Queue page's base filter, so the two
lists agree. The Queue page and the My Work page SHALL stay as they are: no
page is folded, retired or moved by this requirement.

#### Scenario: Mine is the lens you land on
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case assigned to the signed-in user and an open case assigned to another user
- **WHEN** you open the Cases page
- **THEN** the chip Mine SHALL be active
- **AND** the list SHALL show the case assigned to you and SHALL NOT show the other user's case

#### Scenario: Unclaimed shows what nobody has picked up
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case with no assignee and an open case assigned to the signed-in user
- **WHEN** you choose the chip Unclaimed
- **THEN** the list SHALL show the unassigned case and SHALL NOT show the assigned one
- **AND** the Queue page SHALL show the same unassigned case

#### Scenario: All shows every open and closed case
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case assigned to another user and a closed case
- **WHEN** you choose the chip All
- **THEN** the list SHALL show both cases

#### Scenario: Chips replace each other
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** the Cases page with the chip Unclaimed active
- **WHEN** you choose the chip Mine
- **THEN** only Mine SHALL be active
- **AND** the list SHALL show no unassigned case
