# intake-from-a-channel

## ADDED Requirements

### Requirement: A message integriq routed at a case opens one

dossiq SHALL listen to `OCA\Integriq\Event\IntakeMessageRoutedEvent` and, for
an event whose target schema is the case, SHALL open a case and SHALL answer
the event's result slot with it.

The case SHALL be opened through dossiq's own intake path, not written as an
object: it SHALL take the case type the routing rule named, the initial
status that case type declares, the term started the way an intake starts a
term, and the channel recorded as the intake channel. The files the event
carries SHALL be attached to the case.

The listener SHALL be registered only when integriq is installed, and its
absence SHALL change nothing else in the app.

**Feature tier**: MVP

#### Scenario: A form submission becomes a case with its clock running
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** an integriq routing rule that opens a `melding openbare ruimte` for the form submission channel
- **WHEN** a submission arrives on that channel
- **THEN** a case of that type SHALL exist in its type's initial status
- **AND** its statutory term SHALL have started on the day the message arrived
- **AND** the submission's attachments SHALL be on the case

#### Scenario: The message is marked routed, not held
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** the same rule
- **WHEN** the case is opened
- **THEN** integriq's message SHALL be marked routed and SHALL carry the reference to the case
- **AND** it SHALL NOT appear in the review inbox

#### Scenario: An instance without integriq is unaffected
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** an instance running dossiq and no integriq
- **WHEN** the app boots
- **THEN** no listener SHALL be registered and no error SHALL be logged

### Requirement: A correspondent we do not know still gets a case

Where the message's correspondent resolves to a person or an organisation
dossiq knows, that party SHALL be attached to the case as its requester.
Where it does not, the case SHALL carry the name and the address the message
carried, and SHALL still be opened.

dossiq SHALL NOT create a person record to hold an unknown correspondent. A
telephone number that wrote in once is not a citizen record, and a register
filling up with them is worse than a case naming a string.

**Feature tier**: MVP

#### Scenario: A known citizen is attached as requester
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a message whose correspondent matches a person dossiq holds
- **WHEN** the case is opened
- **THEN** that person SHALL be the case's requester

#### Scenario: An unknown correspondent is carried as written
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a message from a correspondent dossiq does not know
- **WHEN** the case is opened
- **THEN** the case SHALL carry the correspondent's name and address as given
- **AND** no person record SHALL have been created

### Requirement: A refusal says why, and integriq keeps the message

Where dossiq refuses to open a case, it SHALL leave the event's result slot
empty and SHALL record the reason. integriq then holds the message in its
review inbox, which is the behaviour it already has for a message no app
claimed.

A refusal SHALL be a decision, never a failure to answer. An exception
escaping the listener SHALL be caught, logged with the message identifier,
and treated as a refusal, so a broken listener holds a message instead of
losing one.

**Feature tier**: MVP

#### Scenario: A triage refusal holds the message with its reason
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a routing rule whose case type dossiq's triage refuses for this message
- **WHEN** the message arrives
- **THEN** no case SHALL be opened
- **AND** the message SHALL be in integriq's review inbox with the reason dossiq gave

#### Scenario: A listener that throws loses nothing
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a message whose case type no longer exists
- **WHEN** it arrives
- **THEN** the message SHALL be held rather than dropped
- **AND** the log SHALL name the message and what was missing
