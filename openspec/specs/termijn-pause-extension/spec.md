---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijn-pause-extension Specification

## Purpose
TBD - created by archiving change termijnbewaking-dwangsom-engine-03-pause-extension. Update Purpose after archive.
## Requirements
### Requirement: Pauze wegens onvolledige aanvraag (REQ-TERM-002)

The system SHALL provide a hersteltermijn pause (AWB 4:5/4:15) that preserves the original deadline window, resuming with only the unconsumed pause days added back.

#### Scenario: Pauze registration extends the deadline

- **GIVEN** a `TermijnInstance` is running with `einddatumActueel` = 2026-07-27
- **WHEN** the handler registers a hersteltermijn-verzoek with `duurDagen` = 14
- **THEN** `status` SHALL be `gepauzeerd`
- **AND** `einddatumActueel` SHALL extend by 14 days to 2026-08-10
- **AND** a `TermijnGebeurtenis` of type `pauze` SHALL be recorded with `dagenImpact` = +14

#### Scenario: Resume consumes only elapsed pause days

- **GIVEN** a 14-day pauze was registered and the burger responds with aanvulling after 8 days
- **WHEN** the handler registers the aanvulling-ontvangst
- **THEN** a `TermijnGebeurtenis` of type `hervat` SHALL be recorded and `status` SHALL revert to `lopend`
- **AND** `einddatumActueel` SHALL be recalculated adding only the 6 unconsumed pause days (original + 6), not the full 14

### Requirement: Verlenging volgens AWB 4:14 (REQ-TERM-003)

The system SHALL allow exactly one motivated extension and SHALL block a second extension unless an exceptional grond is supplied with supervisor approval.

#### Scenario: First extension with valid motivering succeeds

- **GIVEN** a `TermijnInstance` is running and `aantalVerlengingen` = 0
- **WHEN** the handler requests an extension with a non-empty `motivering` and a `newEinddatum` after the current `einddatumActueel`
- **THEN** the extension SHALL be applied: `aantalVerlengingen` becomes 1, `einddatumActueel` = `newEinddatum`
- **AND** a `TermijnGebeurtenis` of type `verleng` SHALL be recorded with the motivering and `dagenImpact`
- **AND** a verlengingsbrief notification trigger SHALL be emitted

#### Scenario: Second extension is blocked

- **GIVEN** a `TermijnInstance` already has `aantalVerlengingen` = 1
- **WHEN** the handler attempts a second extension
- **THEN** the system SHALL reject it with an error citing AWB 4:14 lid 3
- **AND** the only path forward SHALL be a supervisor-approved exceptional-grond override recorded in a separate audit trail

