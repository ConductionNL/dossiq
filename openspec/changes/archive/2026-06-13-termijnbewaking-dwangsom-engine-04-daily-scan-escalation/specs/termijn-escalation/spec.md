---
status: draft
---

# Daily scan and escalation — Specification Delta

## ADDED Requirements

### Requirement: Pro-actieve notificaties bij naderende deadlines (REQ-TERM-004)

The system SHALL run a daily termijn-scan that issues escalating alerts at 14-day, 7-day, and 2-day thresholds and SHALL mark overdue instances as overschreden.

#### Scenario: 14-day handler alert

- **GIVEN** a `TermijnInstance` has `einddatumActueel` over 13 days away
- **WHEN** the daily termijn-scan runs
- **THEN** the handler SHALL receive a notification via Nextcloud and e-mail naming the case and deadline date
- **AND** the same threshold SHALL NOT re-notify on a subsequent run for the same instance

#### Scenario: 7-day and 2-day escalation

- **GIVEN** a `TermijnInstance` has `einddatumActueel` 6 days away
- **WHEN** the daily scan runs
- **THEN** the handler AND teamleader SHALL be notified with elevated priority
- **AND** at the 2-day threshold the handler, teamleader, AND afdelingsmanager SHALL receive a RED-FLAG notification

#### Scenario: Overschrijding detection and status update

- **GIVEN** a `TermijnInstance`'s `einddatumActueel` has passed
- **WHEN** the scan runs on the next day
- **THEN** `status` SHALL be set to `overschreden`
- **AND** a `TermijnGebeurtenis` of type `overschreden` SHALL be recorded
- **AND** all users with access to the case SHALL receive an overschrijding notification

### Requirement: Pause-expiry alert on non-response (REQ-TERM-002-B)

The system SHALL detect an expired hersteltermijn during the daily scan and SHALL advise the handler per AWB 4:5.

#### Scenario: Pause expires without aanvulling

- **GIVEN** a pauze was registered with a pause-deadline that has now passed without an aanvulling
- **WHEN** the daily scan runs
- **THEN** a `TermijnGebeurtenis` of type `pauze-verlopen` SHALL be recorded
- **AND** the handler SHALL receive a pro-active notification advising the AWB 4:5 next step
- **AND** automatic termijn-continuation SHALL be blocked until the handler decides
