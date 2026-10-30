# case-last-activity-at Specification

## Purpose
A stalled case is one nobody worked on, measured from a real activity date rather than the last save.

## Requirements

### Requirement: Work on a case stamps its last activity

The case SHALL carry `lastActivityAt` (date-time). It SHALL be set to the current time when the case's status moves, when
a note is added on it, when a `caseDocument`, `customerContact` or `contactmoment` is created on it, when a task on it is
completed, and when a case is created without one. A case save that leaves the status unchanged SHALL NOT set it.

#### Scenario: A nightly re-save

- **GIVEN** a case last worked on 12 days ago
- **WHEN** the nightly rematerialisation re-saves it with its status unchanged
- **THEN** its `lastActivityAt` stays 12 days ago

#### Scenario: A note

- **WHEN** a handler adds a note on the case
- **THEN** its `lastActivityAt` is now

### Requirement: Stalled lists read the last activity

`daysSinceActivity` SHALL count whole days from `lastActivityAt`, falling back to the creation date, and every stalled-cases
list and its View all link SHALL sort on `lastActivityAt` ascending.

#### Scenario: DqMijnTeam "Vastgelopen zaken"

- **GIVEN** open cases last worked on 12, 8 and 6 days ago and others worked on today
- **THEN** the card lists the three, longest idle first, with "12 dagen stil", "8 dagen stil", "6 dagen stil"
