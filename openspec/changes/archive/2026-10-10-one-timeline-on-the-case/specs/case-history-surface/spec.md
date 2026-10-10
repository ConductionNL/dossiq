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

### Requirement: One entry reaches every case it is about (REQ-TL-13)

An entry recorded against several cases SHALL be written onto each of
them in one act, through OpenRegister's related-objects write, so that
each case carries the entry and each entry names its siblings. A contact
moment logged against a case and its related cases SHALL use that path
rather than one write per case. A case the author may not write on SHALL
refuse the whole write, naming that case, and SHALL NOT leave the entry
on the others. The timeline SHALL say, on an entry that has siblings,
how many other cases carry it.

#### Scenario: a call about three cases lands on three timelines

- **GIVEN** a contact moment logged on a case naming two related cases
- **WHEN** it is recorded
- **THEN** each of the three cases SHALL carry the entry
- **AND** each entry SHALL name the other two

#### Scenario: a related case that cannot be read is named, not skipped in silence

- **GIVEN** a related case the writer cannot read
- **WHEN** the contact moment is recorded
- **THEN** that case SHALL be logged as not carrying the entry

#### Scenario: an entry that is on other cases says so
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** an entry carried by three cases
- **WHEN** a handler reads one of those timelines
- **THEN** the entry SHALL say it is also written on two other cases

### Requirement: The standard notes are administered text, not retyped (REQ-TL-14)

The notes a handler writes over and over SHALL be available as
administered text blocks, seeded by dossiq and inserted into the composer
with the case's own values substituted. A placeholder nothing supplies
SHALL be left standing rather than replaced with an empty string.

#### Scenario: a standard note is inserted with the case filled in
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a seeded text block naming the case number
- **WHEN** a handler picks it in the timeline composer and saves
- **THEN** the composer SHALL send the block's name rather than its body
- **AND** the written note SHALL carry the case's own number

#### Scenario: a handler who may not manage the timeline is not offered the composer
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a reader without `update` on the case
- **WHEN** they open the Timeline tab
- **THEN** the composer SHALL NOT be shown
- **AND** the pin and follow-up controls SHALL NOT be shown

### Requirement: Every status move records itself on the timeline (REQ-TL-15)

Every move of a case from one status to another SHALL write a
`statuswijziging` entry naming both statuses, who made the move, what
they said about it and the `statusRecord` it wrote. The entry SHALL be
internal. A guarded transition, an admin free-form move, an ending act
and a reopen SHALL all be recorded, because all four write a
`statusRecord`. A derived move writes no `statusRecord` and SHALL be
recorded after the save that carried it has landed, never before. No move
SHALL be recorded twice. A failure to write the entry SHALL be logged and
SHALL NOT fail the move.

The sentence a handler reads SHALL name the two statuses. The entry's
`fields` SHALL carry their ids, so a reader filters on the id and reads
the name.

#### Scenario: a handler reads why the case moved and who moved it
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a case a handler moves to another status with a comment
- **WHEN** the move is made
- **THEN** a `statuswijziging` entry SHALL be written
- **AND** it SHALL carry the status it left, the status it entered, the handler and the comment

#### Scenario: a case that closes says so on its own timeline
@e2e exclude The ending acts reach the same seam as a guarded transition, so an e2e over the acts would re-measure the write the scenario above already measures on a running instance.

- **GIVEN** a case a handler finishes
- **WHEN** the ending act writes its status record
- **THEN** a `statuswijziging` entry SHALL be written naming the terminal status

#### Scenario: a status that became true is recorded once the save landed
@e2e exclude A derived status needs a case type declaring its conditions and a write that makes one true, which is the subject of what-a-status-declares and is covered by its own suite; this scenario is about the ordering of the two listeners, which is unit work.

- **GIVEN** a case type declaring the conditions of a status
- **WHEN** a write makes those conditions true and the case is saved
- **THEN** a `statuswijziging` entry SHALL be written after the save
- **AND** it SHALL name no actor and invent no explanation

#### Scenario: a move nobody derived is not written twice
@e2e exclude The absence of a second entry is counted against a double in the unit suite; an e2e would assert a count on a live timeline that other suites also write to.

- **GIVEN** a handler making an ordinary guarded transition
- **WHEN** the case is saved and the status record is written
- **THEN** exactly one `statuswijziging` entry SHALL exist for that move

#### Scenario: the move still lands when the timeline write fails
@e2e exclude The failing timeline is induced by refusing the seam, which is a double's answer rather than a state a running instance can be put into without breaking every other suite in the file.

- **GIVEN** an instance whose timeline write fails
- **WHEN** a handler moves a case to another status
- **THEN** the case SHALL move and the status record SHALL be written as before
- **AND** the failure SHALL be logged

### Requirement: Every term event records itself on the timeline (REQ-TL-16)

Every term event SHALL write a `termijngebeurtenis` entry carrying the
event, when it happened, when the term started, when it now falls due and
the term instance it belongs to. The entry SHALL be internal. A term
starting, pausing, resuming, being extended, being exceeded and being
completed SHALL all be recorded, and so SHALL the aanvullingsverzoek ask
and its answer. A phase term, a planned end and an internal target write
no term event of their own, and their start SHALL be recorded where the
term instance is written. Re-binding a term that is already running SHALL
NOT be recorded as a start. A failure to write the entry SHALL be logged
and SHALL NOT fail the act.

#### Scenario: a suspended term reaches the timeline
@e2e tests/e2e/one-timeline-on-the-case.spec.ts

- **GIVEN** a case with a running statutory term
- **WHEN** the term is suspended for an aanvullingsverzoek
- **THEN** a `termijngebeurtenis` entry SHALL be written
- **AND** it SHALL carry the event, the new due date and the term instance

#### Scenario: a phase clock says it started
@e2e exclude A phase term needs a case type declaring a phase lead time, which phase-terms-and-the-internal-target seeds in its own suite; the write itself has no second answer a running instance could give.

- **GIVEN** a case type declaring a lead time on a phase
- **WHEN** the case enters that phase
- **THEN** a `termijngebeurtenis` entry SHALL be written naming the phase clock and its due date

#### Scenario: moving a running term's end date is not a new start
@e2e exclude Whether a start is announced is counted against a double; a live instance would need two writes of the same term to tell the two apart.

- **GIVEN** a case whose fixed end date moves
- **WHEN** the running term is re-bound to the new date
- **THEN** no `termijngebeurtenis` entry SHALL announce a start

#### Scenario: the term event is kept when the timeline refuses it
@e2e exclude The refusal is a double's answer, as in REQ-TL-15.

- **GIVEN** an instance whose timeline write fails
- **WHEN** a term is suspended
- **THEN** the TermijnGebeurtenis SHALL be written as before
- **AND** the failure SHALL be logged
