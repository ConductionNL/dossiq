# kcc-routing Delta: case-routing-note

## ADDED Requirements

### Requirement: A case keeps a note of its last routing event

A case SHALL hold at most one `routingNote`, naming the facts of its last routing event. A team refusing a handover SHALL
write the note with kind `handover-refused`, level `error`, the refusing team and the reason. An intake fan-out that opened
more than one case, or missed a destination, SHALL write it with kind `intake-split`, level `info` on every case it opened,
naming the submission, the sibling cases and each failed destination with its reason. Failing to write the note SHALL NOT
fail the handover or the fan-out. Picking the case up SHALL clear the note.

#### Scenario: A refused handover

- **GIVEN** a case handed to team Toezicht
- **WHEN** Toezicht refuses it, with a reason
- **THEN** the case goes back to the sending team
- **AND** its note names Toezicht and the reason

#### Scenario: A fan-out that missed a destination

- **GIVEN** a form with two destinations, one of them failing
- **WHEN** the submission is filed
- **THEN** the case that was opened carries a note naming the submission and the failed destination with its reason

#### Scenario: Picked up

- **GIVEN** a case with a note in the team queue
- **WHEN** a handler claims it
- **THEN** the note is gone

### Requirement: The team queue says why a case stands there

The team queue row SHALL draw the case's `routingNote` under its meta line, in the reader's language, in the error tone for
level `error`. A case without a note SHALL read as before.

#### Scenario: Board DqTeamwachtrij

- **GIVEN** case 2026-4864 with a refused handover
- **WHEN** the team queue opens
- **THEN** its row reads the refusal under the meta line, in the error tone
