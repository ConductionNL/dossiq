## ADDED Requirements

### Requirement: One queue holds everything waiting on a person (REQ-QUEUE-02)

A person's queue SHALL hold, from the declared sources, the cases assigned
to them, the cases where they hold the coordinator seat, their open tasks,
consultations asked of them, approvals awaiting their signature, mentions
of them, and work they cover for an absent colleague. An item SHALL leave
the queue when the thing it points at is done, taken over or withdrawn. A
person SHALL NOT be able to dismiss an item whose work still stands.

#### Scenario: a caseworker opens one page, not six
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler with assigned cases, a coordinator seat, two open tasks and one consultation
- **WHEN** they open their queue
- **THEN** all of them SHALL be listed

#### Scenario: an item closes with its work
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a queue item pointing at an open task
- **WHEN** the task is completed
- **THEN** the item SHALL leave the queue

#### Scenario: a person cannot dismiss live work
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a queue item for a case still assigned to the person
- **WHEN** they try to remove it
- **THEN** it SHALL stay
- **AND** they SHALL be offered to hide its group for today instead

#### Scenario: covering for an absent colleague reaches the queue

- **GIVEN** a handler covering for an absent colleague
- **WHEN** they open their queue
- **THEN** the colleague's waiting work SHALL be listed and marked as covered

### Requirement: A daily digest arrives only when there is something to say (REQ-QUEUE-03)

dossiq SHALL send a person a daily digest of their open work, at a time
they choose, over the platform's notification dialect. A person with an
empty queue SHALL receive no digest. The digest SHALL name what is waiting
and what is overdue and SHALL link into the queue. It SHALL NOT repeat the
assignment notice. It SHALL be switchable off through the platform's
notification preferences.

#### Scenario: the digest arrives at the chosen time
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler with four waiting items and a chosen time of 08:00
- **WHEN** the digest job runs
- **THEN** they SHALL receive one message naming those four

#### Scenario: an empty queue sends nothing
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler with an empty queue
- **WHEN** the digest job runs
- **THEN** they SHALL receive no message

#### Scenario: the digest is not the assignment notice

- **GIVEN** a case assigned to a handler this morning
- **WHEN** the digest runs that evening
- **THEN** the digest SHALL list the case
- **AND** it SHALL NOT be the assignment notice message

#### Scenario: a person switches it off where they switch off everything else

- **GIVEN** a handler who disabled the digest in the notification preferences
- **WHEN** the digest job runs
- **THEN** they SHALL receive no message

### Requirement: One screen closes out the day (REQ-QUEUE-04)

dossiq SHALL offer a screen listing everything a person touched today,
with a place to record an update per item. Where humaniq is present, the
screen SHALL place humaniq's hours leaf per item so time is recorded
there. dossiq SHALL NOT store hours. Where humaniq is absent, the screen
SHALL show no time field.

#### Scenario: everything touched today, in one place
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler who touched five cases and two tasks today
- **WHEN** they open the end-of-day screen
- **THEN** all seven SHALL be listed

#### Scenario: an update is recorded per item
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** the end-of-day screen
- **WHEN** a handler writes an update against one case
- **THEN** it SHALL be recorded on that case

#### Scenario: time goes to humaniq, or nowhere
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** an instance with humaniq present
- **WHEN** a handler records time on an item
- **THEN** it SHALL be written through humaniq's hours leaf

#### Scenario: no humaniq, no time field

- **GIVEN** an instance without humaniq
- **WHEN** the end-of-day screen is opened
- **THEN** no time field SHALL be offered

### Requirement: A person plans an item with no case (REQ-QUEUE-05)

A person SHALL be able to plan an item on their own agenda without
attaching it to a case, optionally from a template. It SHALL be a calendar
event on that person's calendar, SHALL reach their queue as a declared
source, and SHALL NOT be a case, SHALL NOT enter any case count, and SHALL
NOT appear in any case report.

#### Scenario: a planned item with no case
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler
- **WHEN** they plan an item from a template with no case
- **THEN** it SHALL appear on their calendar and in their queue

#### Scenario: it is not a case

- **GIVEN** a planned item with no case
- **WHEN** the open case count and the case list are read
- **THEN** it SHALL be in neither

### Requirement: A person keeps a private stage on a shared case (REQ-QUEUE-06)

A person SHALL be able to set their own stage on a case, visible only to
them. It SHALL NOT change the case's status, SHALL NOT be visible to any
other person, and SHALL NOT enter any report. The case page SHALL show the
case's own status prominently and the personal stage as private to the
reader.

#### Scenario: a personal triage lane on a shared case
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a case shared by two handlers
- **WHEN** the first sets their personal stage to Wachten op advies
- **THEN** the second SHALL NOT see it

#### Scenario: the case's own status is unchanged
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a case in status In behandeling
- **WHEN** a handler sets a personal stage
- **THEN** the case status SHALL still read In behandeling

#### Scenario: a personal stage is not a report dimension

- **GIVEN** cases carrying personal stages
- **WHEN** a status report is run
- **THEN** it SHALL group by the case status only
