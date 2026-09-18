## ADDED Requirements

### Requirement: A case note leaves for the neighbouring register as a document

A note on a case bound to an external ZGW register SHALL be pushed as a
`zaakinformatieobject` of the reserved note type, over the existing
document push. dossiq SHALL NOT invent a private note resource and SHALL
NOT append notes to the case description, because appending overwrites one
note with the next and loses its author.

The push is a deliberate act on a note rather than a hook on saving one,
and that is measured rather than preferred. Note storage is OpenRegister's
entirely under ADR-022: dossiq never reads or writes a note, and
OpenRegister dispatches no note-saved event dossiq could listen for. The
`notes#mention` endpoint beside this one exists for exactly that reason and
works exactly that way, as a call the frontend makes after the note is
already stored. Pushing on save needs an event that does not exist, which
is an openregister row and not this one.

#### Scenario: An external note reaches the neighbouring register
@e2e exclude an outbound push to a register no test instance runs; covered by NoteEnvelopeTest and NotePushTest

- **GIVEN** a case bound to an external ZGW register
- **AND** a note on it that is not internal
- **WHEN** the note is pushed
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

Every push SHALL answer its outcome, and SHALL record it on the case: not
sent, sent, or failed with the reason. A case with no external register
SHALL have nothing recorded at all. A dormant adapter SHALL record nothing,
because a marker that reads as a success on an adapter that contacts
nothing is the failure this rule exists for.

The outcome is recorded on the CASE TIMELINE and not as a field on the
note, because a note is an OpenRegister comment and dossiq cannot add a
field to one. The timeline is where every other thing that happened to this
case already is, so a handler looking for what became of a note looks in
one place rather than two. A per-note badge in the notes panel needs the
library's notes tab to carry a slot for one; it does not, and adding it is
a nextcloud-vue change.

#### Scenario: A refused push leaves the outcome recorded as failed

- **GIVEN** a case bound to an external register whose adapter refuses
- **WHEN** an external note is pushed
- **THEN** the answer SHALL read failed, with the adapter's reason
- **AND** the case SHALL record it as failed rather than as sent

#### Scenario: A dormant adapter writes no marker
@e2e exclude a configuration branch over a dormant adapter; covered by the push guard test

- **GIVEN** a case whose external adapter is dormant
- **WHEN** an external note is pushed
- **THEN** no push outcome SHALL be recorded on the case
- **AND** the answer SHALL say the case is bound to no external register

#### Scenario: A caller who may not change the case cannot send its notes
@e2e exclude covered by the e2e spec's anonymous arm and the controller guard

- **GIVEN** a caller with no mutation access to the case
- **WHEN** that caller calls the push endpoint
- **THEN** the response SHALL be a refusal
- **AND** nothing SHALL be sent to the neighbouring register
