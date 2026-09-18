# intake-from-a-channel

## ADDED Requirements

### Requirement: A message integriq routed at a case opens one

dossiq SHALL listen to `OCA\Integriq\Event\IntakeMessageRoutedEvent` and, for
an event whose target schema is the case, SHALL open a case and SHALL answer
the event's result slot with it.

The case SHALL be opened through dossiq's own intake path, not written as an
object: it SHALL take the case type the routing rule named, the initial
status that case type declares, the term started the way an intake starts a
term, and the channel recorded as the intake channel. The case type SHALL be
resolved before the case is written, and a rule naming one this instance does
not have SHALL be refused rather than opening a case with no type.

🔴 THE ATTACHMENTS ARE NOT CARRIED YET, and this requirement says so rather
than implying they are. integriq's event carries the files, and putting them
in the case folder is a seam of its own with its own refusals (size, scan
verdict, naming). A case opened from a message with a photo therefore names
the message and not the photo, and the photo is reachable from integriq. The
follow-up is `channel-attachments-reach-the-case`.

The listener SHALL be registered only when integriq is installed, and its
absence SHALL change nothing else in the app.

**Feature tier**: MVP

#### Scenario: A form submission becomes a case with its clock running
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** an integriq routing rule that opens a `melding openbare ruimte` for the form submission channel
- **WHEN** a submission arrives on that channel
- **THEN** a case of that type SHALL exist in its type's initial status
- **AND** its statutory term SHALL have started on the day the message arrived
- **AND** the case SHALL record the channel it came in on

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

### Requirement: A correspondent nobody knows still gets a case

The case SHALL carry the correspondent as the message gave them, written onto
the initiator's reference in its source rather than left inside the
description. An address that lives only in prose is one no code can reach,
and Awb 4:3a owes whoever wrote in a confirmation of receipt.

dossiq SHALL NOT create a person record for a correspondent, and SHALL NOT
refuse a message because it does not recognise one. A handle that wrote in
once is not a citizen record, and a register filling up with them is worse
than a case naming a string.

🔴 RESOLVING A CORRESPONDENT TO A PARTY dossiq ALREADY HOLDS IS NOT DONE
HERE, and this says so rather than implying it. A case opened from a channel
names the handle; joining that handle to the person behind it is
`channel-correspondents-resolve-to-parties`.

**Feature tier**: MVP

#### Scenario: The correspondent is written where code can read it
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a message from a correspondent dossiq does not know
- **WHEN** the case is opened
- **THEN** the case SHALL carry that correspondent as the initiator's reference
- **AND** no person record SHALL have been created

#### Scenario: A rule that mapped the initiator itself wins
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a routing rule that maps the initiator's reference itself
- **WHEN** the case is opened
- **THEN** the mapped value SHALL stand, and the correspondent SHALL NOT overwrite it

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

### Requirement: A refusal is readable on the intake log, not only in the server log

Every routed message dossiq answers SHALL be recorded on the intake log, the
surface an intake worker already reads, with the channel it arrived on, the
channel's own id for it, the outcome and the reason in a sentence. A message
that opened a case SHALL name the case; one that did not SHALL name why.

Recording SHALL NOT be limited to the server log. integriq holds an
unanswered message with "No app opened a case for this message", which was
true while nothing listened. Once dossiq listens, a refusal that reaches only
`nextcloud.log` leaves that sentence on the screen of the person who has to
act on it, and it is now false.

**Feature tier**: MVP

#### Scenario: The refusal is on the page with its reason
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a channel message dossiq refused because its rule named no case type this instance has
- **WHEN** an intake worker opens the intake log and searches for the correspondent
- **THEN** the entry SHALL be shown
- **AND** it SHALL carry the reason in words, not only the word refused

#### Scenario: An opened case is recorded too
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a channel message that opened a case
- **WHEN** the intake worker reads the log
- **THEN** the entry SHALL name the case and the rule that opened it

### Requirement: A second delivery of one message opens no second case

What makes two deliveries the same message SHALL be the channel plus the
channel's own id for it. dossiq SHALL read its own intake log for that pair
before opening anything, and SHALL answer the result slot with the case the
first delivery opened.

It SHALL NOT be integriq's `intake_message` uuid. That uuid is minted per
delivery, so two deliveries of one message carry two of them, and a check on
it opens a second case and reports success on both.

A message carrying no channel or no id of its own SHALL be refused with that
reason, because a retry of it could not be told from a new message. Where the
intake log cannot be read at all, dossiq SHALL refuse rather than open a case
it cannot recognise again.

**Feature tier**: MVP

#### Scenario: The same message delivered twice
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a channel message that already opened a case
- **WHEN** the channel delivers it again, under a new integriq message uuid
- **THEN** no second case SHALL be opened
- **AND** the second delivery SHALL be answered with the case the first one opened

#### Scenario: A message with no id of its own
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a routed message carrying no `externalId`
- **WHEN** it arrives
- **THEN** no case SHALL be opened
- **AND** the reason SHALL say a second delivery could not be recognised

### Requirement: The message never names the identity, the assignee or the status

The case SHALL be written as the system principal, because a channel message
arrives with no Nextcloud session and an anonymous create is refused. The
payload SHALL be built by dossiq from an allowlist of descriptive fields, and
the mapped values a routing rule carries SHALL NOT be passed through.

A value the message or the rule carries SHALL NOT set the assignee, the
status, a grant, the register or the schema. The status SHALL come from the
case type. Whatever the elevation allows, the message decides nothing about
who may read the case or who holds it.

A rule naming a target that is not this app's case SHALL be left alone, so a
message addressed to another app is not taken from it.

**Feature tier**: MVP

#### Scenario: A mapped assignee is dropped
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a routing rule mapping `assignee`, `status` and `grants` onto the case
- **WHEN** a message opens a case through it
- **THEN** the case SHALL carry none of those three
- **AND** its status SHALL be the one its case type declares

#### Scenario: Another app's message is left alone
@e2e tests/e2e/intake-from-a-channel.spec.ts

- **GIVEN** a routed message whose rule names a target that is not dossiq's case
- **WHEN** it arrives
- **THEN** dossiq SHALL answer nothing and record nothing
