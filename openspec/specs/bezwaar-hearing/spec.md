## Purpose

Schedules and manages hoorzittingen (hearings) within the bezwaar process per the AWB hoorrecht (art. 7:2 e.v.), including invitations, minutes, hearing waiver (afzien van hoorrecht), and participant access rights.

@e2e exclude Bezwaar hearing management is V1; hearing schema and scheduling are not yet built in the Playwright-testable UI.

## Requirements

### Requirement: Hearing Session Management

The system SHALL support scheduling and managing hoorzittingen (hearings) as part of the bezwaar process. The hearing is a legal right under AWB art. 7:2 -- the bezwaarmaker MUST be offered the opportunity to be heard before a decision on the objection is made.

**Feature tier**: V1
**CMMN mapping**: HumanTask within bezwaar CasePlanModel
**Schema.org mapping**: `schema:Event` with `schema:about` referencing the bezwaar case

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `case` | reference (UUID) | Yes | The bezwaar case this hearing belongs to |
| `scheduledDate` | datetime | Yes | Date and time of the hearing |
| `location` | string | No | Physical location or "Online" for video hearings |
| `videoCallUrl` | string (URL) | No | Video conference link for online hearings |
| `chairperson` | reference (UUID to role) | Yes | Who chairs the hearing (voorzitter) |
| `members` | array of references | No | Committee members present |
| `invitees` | array of objects | Yes | Persons invited (bezwaarmaker, gemachtigde, primair beslisser) |
| `minutesSummary` | string (text) | No | Summary of what was discussed (verslag) |
| `minutesDocument` | reference (UUID) | No | Full hearing minutes document |
| `status` | enum | Yes | gepland, uitgenodigd, uitgevoerd, geannuleerd, afgezien |
| `hearingWaived` | boolean | No | Bezwaarmaker has waived the right to be heard |
| `waiverReason` | string | No | Reason for waiving hearing right |

#### Scenario: Schedule a hearing for a bezwaar case

- **WHEN** the behandelaar schedules a hearing for bezwaar case BZ-2026-0042
- **THEN** a hearingSession object SHALL be created with status "gepland"
- **AND** the case status SHALL transition to "Hoorzitting gepland"
- **AND** the system SHALL validate that the hearing date is at least 5 working days in the future (to allow preparation time)

#### Scenario: Send hearing invitations

- **WHEN** a hearing is scheduled with status "gepland"
- **AND** the behandelaar triggers the invitation action
- **THEN** the system SHALL create a notification for each invitee
- **AND** the hearing status SHALL change to "uitgenodigd"
- **AND** the invitation SHALL include: date, time, location, subject of the bezwaar, and the right to bring witnesses (art. 7:8)

#### Scenario: Record hearing minutes

- **WHEN** the hearing has taken place
- **AND** the chairperson marks the hearing as "uitgevoerd"
- **THEN** the system SHALL require a `minutesSummary` to be filled in
- **AND** optionally allow uploading a full minutes document
- **AND** the case status SHALL transition to "Hoorzitting afgerond"

### Requirement: Hearing Waiver (Afzien van Hoorrecht)

The system SHALL allow the bezwaarmaker to waive their right to a hearing, as permitted under AWB art. 7:3. When the right is waived, the hearing steps SHALL be skipped.

**Feature tier**: V1

#### Scenario: Bezwaarmaker waives hearing right

- **WHEN** the bezwaarmaker indicates they do not wish to be heard
- **THEN** the behandelaar SHALL record this by creating a hearingSession with `hearingWaived: true` and `waiverReason`
- **AND** the case SHALL be able to skip the "Hoorzitting gepland" and "Hoorzitting afgerond" statuses
- **AND** the waiver SHALL be recorded in the case audit trail

#### Scenario: Hearing waiver cannot be recorded after hearing is completed

- **WHEN** a hearing has already been held (status "uitgevoerd")
- **THEN** the system SHALL NOT allow setting `hearingWaived` to true
- **AND** an error message SHALL display: "Hoorzitting heeft reeds plaatsgevonden"

### Requirement: Hearing Participants and Access Rights

The system SHALL manage hearing participants with appropriate access rights as defined in AWB art. 7:4 through 7:8.

**Feature tier**: V1

#### Scenario: Bezwaarmaker may bring a representative

- **WHEN** the bezwaarmaker is invited to a hearing
- **THEN** the invitation SHALL state that they may bring a gemachtigde (authorized representative)
- **AND** the system SHALL allow adding a "Vertegenwoordiger" role to the case for the gemachtigde

#### Scenario: Bezwaarmaker may inspect case documents before hearing

- **WHEN** a hearing is scheduled for bezwaar case BZ-2026-0042
- **THEN** the bezwaarmaker SHALL have the right to inspect all case documents for at least 1 week before the hearing (art. 7:4 lid 2)
- **AND** the system SHALL track which documents are part of the bezwaardossier
