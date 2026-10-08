## ADDED Requirements

### Requirement: A live conversation runs from any case (REQ-LIVE-01)

A handler SHALL be able to start a live conversation from any case, in
Nextcloud Talk, through the same broker the hearing already uses. The
bezwaar hoorzitting SHALL become one configured use of it rather than the
only one. dossiq SHALL ship no calling, no recorder and no signalling of
its own.

#### Scenario: a conversation starts from an ordinary case
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a vergunning case
- **WHEN** a handler starts a conversation from it
- **THEN** a Talk room SHALL be created and linked to the case

#### Scenario: the hoorzitting still works as it did
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a bezwaar with a scheduled video hearing
- **WHEN** the hearing is opened
- **THEN** it SHALL behave as before
- **AND** it SHALL use the same conversation mechanism

#### Scenario: no Talk, no affordance

- **GIVEN** an instance without the Talk app
- **WHEN** a handler opens a case
- **THEN** no conversation affordance SHALL be offered
- **AND** the case SHALL say why

### Requirement: The conversation is recorded on the case (REQ-LIVE-02)

A conversation SHALL be recorded on the case with the moment it started,
who joined, and how long it lasted. What it produces, a recording, a
transcript or minutes, SHALL become a document on the case. What the
citizen may see of it SHALL be decided by the case type's visibility
declaration, and SHALL NOT be readable in the portal by default.

#### Scenario: who was heard, and when
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a conversation two people joined
- **WHEN** it ends
- **THEN** the case SHALL record the moment, both participants and the duration

#### Scenario: a recording becomes a case document
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a recorded conversation
- **WHEN** it ends
- **THEN** the recording SHALL be a document on the case

#### Scenario: the citizen does not get it by default

- **GIVEN** a recording on a case type that does not declare it visible
- **WHEN** the citizen opens the case in the portal
- **THEN** the recording SHALL NOT be listed

### Requirement: A voice note or a capture attaches to a case or a task (REQ-LIVE-03)

A person SHALL be able to attach a voice note or a screen capture to a case
or to a task, where the platform provides a recorder. It SHALL land as a
case document under the same visibility rules as a conversation recording.
Where the platform provides no recorder, the affordance SHALL be absent
and dossiq SHALL NOT provide one.

#### Scenario: a constatering ter plaatse is a photo and a voice note
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a toezichtzaak
- **WHEN** an inspector attaches a voice note
- **THEN** it SHALL be a document on the case

#### Scenario: a capture on a task follows the task
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** an open task with a capture attached
- **WHEN** the task completes
- **THEN** the capture SHALL be a document on the case

#### Scenario: no recorder, no affordance

- **GIVEN** a platform with no recorder
- **WHEN** a handler opens the case
- **THEN** no capture affordance SHALL be offered

### Requirement: A major case opens one channel with named responders (REQ-LIVE-04)

A case SHALL be declarable major by a permissioned act. Declaring it SHALL
open exactly one working channel and SHALL notify the responders the case
type names, over the notification dialect. The declaration SHALL record who
made it, when, and who was pulled in. A case type whose responders cannot
be resolved SHALL refuse the declaration rather than open an empty
channel.

#### Scenario: a calamiteit is stood up in one act
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a case type naming four responders
- **WHEN** a coordinator declares the case major
- **THEN** one channel SHALL open
- **AND** the four SHALL be notified and recorded

#### Scenario: one channel, not two
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a case already declared major
- **WHEN** a second person declares it major
- **THEN** no second channel SHALL open
- **AND** they SHALL be taken to the existing one

#### Scenario: unresolvable responders refuse the declaration

- **GIVEN** a case type naming a responder group that does not resolve
- **WHEN** a coordinator declares the case major
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the group

#### Scenario: the channel closes with the case and its content stays
@e2e tests/e2e/live-conversation-on-the-case.spec.ts

- **GIVEN** a major case with an open channel
- **WHEN** the case is closed
- **THEN** the channel SHALL close
- **AND** what was said SHALL be filed on the case
