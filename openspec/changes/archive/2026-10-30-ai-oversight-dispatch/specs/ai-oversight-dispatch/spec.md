# ai-oversight-dispatch

## ADDED Requirements

### Requirement: Every AI suggestion shown on a case ends in one oversight report

dossiq SHALL report what a person did with each AI suggestion on a case to hermiq through
`AiOversightRecordedEvent`, with origin app `dossiq` and the case as subject: accepted, modified or rejected when the
person acts on it, and ignored with `unusedBecause` (`superseded`, `closed` or `expired`) when they do not. Without
hermiq the report SHALL be a no-op and SHALL NOT fail the person's action.

#### Scenario: A used feature run

- **GIVEN** a feature run on a case shows its output
- **WHEN** the handler uses it
- **THEN** dossiq reports `accepted` with the output as the suggestion

#### Scenario: An adapted answer

- **WHEN** the handler adapts an answer and uses their version
- **THEN** dossiq reports `modified` with their version as the actual value

#### Scenario: A suggestion nobody acted on

- **GIVEN** an answer nobody acted on
- **WHEN** a new question replaces it, the panel closes, or 30 minutes pass
- **THEN** dossiq reports it `ignored` with `superseded`, `closed` or `expired`

#### Scenario: A redaction proposal is reviewed

- **WHEN** a reviewer approves a proposal as proposed, approves edited spans, or rejects it
- **THEN** dossiq reports `accepted`, `modified` or `rejected`
- **AND** a pending proposal replaced by a new one is reported `ignored`, `superseded`

#### Scenario: A report on a case the caller may not read

- **WHEN** a caller reports an action on a case they may not read
- **THEN** dossiq answers 403 and records nothing
