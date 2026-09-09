## ADDED Requirements

### Requirement: REQ-CASE-LTA-001 The Cases index MUST offer a Due this week lens

You see the week ahead without reading every deadline. The `Cases` page SHALL
carry a Due this week chip after Overdue, filtering
`deadline[gte] = "@today"`, `deadline[lt] = "@today+7d"` and
`isFinalStatus = false`.

The window is half-open on both sides. Without the near edge the chip would
list every overdue case as well and still read as a plausible list, which is
the failure mode a reader cannot see.

The chip SHALL NOT carry a `statusHiddenInLists` condition, matching its
sibling Overdue rather than All.

#### Scenario: Due this week shows the case due in three days and neither neighbour
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case due in three days, an open case due in thirty days and an open case that was due two days ago
- **WHEN** you choose the chip Due this week
- **THEN** the list SHALL show the case due in three days
- **AND** the list SHALL NOT show the case due in thirty days
- **AND** the list SHALL NOT show the case that was due two days ago
