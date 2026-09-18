## ADDED Requirements

### Requirement: A case note leaves for the neighbouring register as a document

A note on a case bound to an external ZGW register SHALL be pushed as a
`zaakinformatieobject` of the reserved note type, over the existing
document push. dossiq SHALL NOT invent a private note resource and SHALL
NOT append notes to the case description, because appending overwrites one
note with the next and loses its author.

#### Scenario: An external note reaches the neighbouring register
@e2e exclude an outbound push to a register no test instance runs; covered by NoteEnvelopeTest and the adapter contract test

- **GIVEN** a case bound to an external ZGW register
- **AND** a note on it that is not internal
- **WHEN** the note is saved
- **THEN** a document envelope SHALL be pushed carrying the note's text, its
  author and the case
- **AND** its informatieobjecttype SHALL be the reserved note type

#### Scenario: An empty note is refused before it is sent
@e2e exclude a validation branch with no surface; covered by NoteEnvelopeTest

- **GIVEN** a note with no text
- **WHEN** a push is attempted
- **THEN** nothing SHALL be sent
- **AND** the refusal SHALL say the note was empty

### Requirement: An internal note stays here

Only a note marked not internal SHALL be pushed. The marker SHALL be the
one the timeline already writes, and no second flag SHALL be added, because
two flags disagree the first time one of them is edited. The default SHALL
remain internal, so nothing leaves the municipality by accident.

#### Scenario: A note left on the default never leaves
@e2e exclude the same outbound path; covered by the push guard test

- **GIVEN** a case bound to an external register
- **AND** a note saved with no visibility chosen
- **WHEN** the push guard runs
- **THEN** nothing SHALL be sent
- **AND** making the same note external SHALL send it

### Requirement: A note that did not leave says so on the case

Every note on a bound case SHALL carry its push outcome: not sent, sent, or
failed with the reason. A case with no external register SHALL show no
marker at all. A dormant adapter SHALL write no marker, because a marker
that reads as a success on an adapter that contacts nothing is the failure
this rule exists for.

#### Scenario: A refused push leaves the note marked failed

- **GIVEN** a case bound to an external register whose adapter refuses
- **WHEN** an external note is saved
- **THEN** the note SHALL read failed, with the adapter's reason
- **AND** it SHALL NOT read sent

#### Scenario: A dormant adapter writes no marker
@e2e exclude a configuration branch over a dormant adapter; covered by the push guard test

- **GIVEN** a case whose external adapter is dormant
- **WHEN** an external note is saved
- **THEN** no push outcome SHALL be written on the note
- **AND** the notes panel SHALL show no marker on that case
