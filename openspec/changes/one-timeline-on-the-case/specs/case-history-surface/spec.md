## ADDED Requirements

### Requirement: The case carries one timeline of everything said about it (REQ-TL-10)

A case SHALL carry one chronological timeline holding every note, logged
contact moment, inbound and outbound message and acknowledgement recorded
against it, newest first, with pinned entries above the rest. The
timeline SHALL be an OpenRegister timeline read; dossiq SHALL NOT keep a
timeline store of its own. The change history SHALL stay in the audit
sidebar.

#### Scenario: a handler reads one order instead of four tabs
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a case with a note, a logged call and a sent mail
- **WHEN** a handler opens the Timeline tab
- **THEN** all three SHALL be listed in one order, newest first
- **AND** each SHALL name its kind and its author

#### Scenario: a pinned entry is read first
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a timeline with a pinned entry that is not the newest
- **WHEN** a handler opens the Timeline tab
- **THEN** the pinned entry SHALL be shown above the unpinned ones

#### Scenario: a read that fails is not an empty case

- **GIVEN** an instance whose OpenRegister cannot be reached
- **WHEN** a handler opens the Timeline tab
- **THEN** it SHALL show that the timeline could not be read
- **AND** it SHALL NOT show an empty timeline

### Requirement: dossiq declares the kinds it writes (REQ-TL-11)

dossiq SHALL declare its timeline kinds through OpenRegister once per
instance, in a repair step that is idempotent and never throws. The
declared kinds SHALL be a contact moment carrying channel and direction,
inbound mail, outbound mail, a portal message, an acknowledgement of
receipt, a status change and a term event. A kind SHALL declare a
follow-up only when every entry of that kind needs an answer.

#### Scenario: declaring twice changes nothing

- **GIVEN** an instance where the kinds are already declared
- **WHEN** the repair step runs again
- **THEN** each declaration SHALL be rewritten in place
- **AND** no second kind SHALL be created for the same name

#### Scenario: OpenRegister without the timeline does not break the upgrade

- **GIVEN** an instance whose OpenRegister does not carry timeline kinds
- **WHEN** the repair step runs
- **THEN** it SHALL record that nothing was declared
- **AND** it SHALL NOT throw

#### Scenario: an inbound message stays open until a handler closes it

- **GIVEN** the inbound-mail kind
- **WHEN** an entry of that kind is written
- **THEN** it SHALL carry an open follow-up

### Requirement: Every communication writer records on the timeline (REQ-TL-12)

Every dossiq writer that records a communication on a case SHALL write a
timeline entry of its kind, carrying the id of its own record in
`fields`. The entry SHALL be internal unless what it records has already
left the counter, in which case it SHALL be public. A failure to write
the entry SHALL be logged and SHALL NOT fail the act being recorded.

#### Scenario: a logged call reaches the timeline
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a case
- **WHEN** a handler logs a contact moment on it
- **THEN** a `contactmoment` entry SHALL be written carrying the channel, the direction and the contact moment's id
- **AND** it SHALL be internal

#### Scenario: an acknowledgement of receipt is public

- **GIVEN** a case that owes an acknowledgement of receipt
- **WHEN** the acknowledgement is sent
- **THEN** an `ontvangstbevestiging` entry SHALL be written
- **AND** its visibility SHALL be public

#### Scenario: a portal message does not carry the recipient's BSN

- **GIVEN** a Berichtenbox dispatch for a citizen
- **WHEN** the message is sent
- **THEN** a public `portaalbericht` entry SHALL be written
- **AND** its fields SHALL NOT contain the BSN

#### Scenario: the mail still goes out when the timeline write fails

- **GIVEN** an instance whose timeline write fails
- **WHEN** a handler sends a mail from the case
- **THEN** the mail SHALL be sent and recorded as before
- **AND** the failure SHALL be logged

### Requirement: One note reaches several cases at once (REQ-TL-13)

A note written from the case timeline SHALL be writable onto several
related cases in one act, through OpenRegister's related-objects write,
so that each case carries the entry and each entry names its siblings. A
case the author may not write on SHALL refuse the whole write, naming
that case, and SHALL NOT leave the note on the others.

#### Scenario: one note, three cases
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** three related cases the handler may write on
- **WHEN** they write one note naming all three
- **THEN** each case SHALL carry the entry
- **AND** each entry SHALL name the other two

#### Scenario: a case the author may not write on refuses the whole note

- **GIVEN** three cases, one of which the handler may not write on
- **WHEN** they write one note naming all three
- **THEN** the write SHALL be refused naming that case
- **AND** no entry SHALL be written on any of the three

### Requirement: The standard notes are administered text, not retyped (REQ-TL-14)

The notes a handler writes over and over SHALL be available as
administered text blocks, seeded by dossiq and inserted into the composer
with the case's own values substituted. A placeholder nothing supplies
SHALL be left standing rather than replaced with an empty string.

#### Scenario: a standard note is inserted with the case filled in
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a seeded text block naming the case number
- **WHEN** a handler picks it in the timeline composer
- **THEN** the note SHALL carry the case's own number
