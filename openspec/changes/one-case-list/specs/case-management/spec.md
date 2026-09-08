## ADDED Requirements

### Requirement: REQ-CM-24 Open work by default, closed work on request

Your work list shows open cases only unless you ask for closed ones. On the
`Cases` page the chips Mine and Unclaimed MUST carry `isFinalStatus = false`
and the chip Closed MUST carry `isFinalStatus = true`, so a closed case
appears under Closed and under All and nowhere else. `isFinalStatus` is a
stored boolean on every case row, so plain equality reaches it and no
derived filter is needed.

#### Scenario: A closed case leaves Mine
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** a case assigned to the signed-in user whose status is final
- **WHEN** you open the Cases page with the chip Mine active
- **THEN** the list SHALL NOT show the closed case

#### Scenario: Closed shows the closed case
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** a case whose status is final and an open case
- **WHEN** you choose the chip Closed
- **THEN** the list SHALL show the closed case and SHALL NOT show the open one

### Requirement: REQ-CM-25 Deadline before in the sidebar

You narrow the case list on a deadline. The `Cases` page sidebar MUST offer
a filter Deadline before, a date input that adds `deadline lt <date>` to the
active query. It MUST combine with the active chip rather than replace it,
so Mine plus Deadline before shows your cases due before that date. The
Requester text filter on `initiatorDisplayName` sits in the same sidebar and
is specified by `requester-on-the-case` (`initiator-display` REQ-ID-2); this
requirement does not restate it.

#### Scenario: Deadline before narrows the list
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** two open cases assigned to the signed-in user, one due in 3 days and one due in 30 days
- **WHEN** you set Deadline before to 10 days from today
- **THEN** the list SHALL show the case due in 3 days and SHALL NOT show the case due in 30 days
- **AND** the chip Mine SHALL still be active
