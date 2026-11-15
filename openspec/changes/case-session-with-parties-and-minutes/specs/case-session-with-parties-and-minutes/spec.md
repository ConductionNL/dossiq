## ADDED Requirements

### Requirement: A case type declares the sessions a case holds (REQ-CSS-1)
A case type MUST be able to declare sessions in `sessions[]`. Each session has a
`key` and MAY name a label, the invitee roles, the minimum number of invitees,
the inspection floor in days, whether a party may waive it, whether minutes and
recording consent are required, the attendance grace window in hours, and the
status written when it is planned, waived, executed or cancelled. Absent fields
take the documented defaults. A session that is not well formed, or two with
one key, MUST be refused with a sentence that names the session and the field.

#### Scenario: A key alone gets the defaults
@e2e exclude Read by SessionDefinitionTest against the real caseType schema; the browser journey belongs to the package that declares the first session (bezwaar, R1).
- **GIVEN** a session entry with only `key: talk`
- **WHEN** it is read
- **THEN** its label MUST be `talk`, one invitee MUST be required, the inspection floor MUST be 0 days and minutes MUST be required

#### Scenario: A status for an unknown step is refused
@e2e exclude Validation only, covered by SessionDefinitionTest.
- **GIVEN** a session entry with `statuses: {archived: gearchiveerd}`
- **WHEN** it is read
- **THEN** it MUST be refused naming the step `archived`

### Requirement: Planning a session keeps the inspection floor and the invitee roles (REQ-CSS-2)
Planning MUST refuse a session the case type does not declare, a start closer
than the inspection floor, fewer invitees than the minimum, and an invitee whose
role is not among the declared roles, and MUST store nothing when it refuses. A
planned session MUST carry its `sessionKey`, its `title` (the label), the
inspection deadline (start minus the floor), the date the file opened for
inspection, each invitee's `invitedAt`, and the declared planned status.

#### Scenario: A hearing inside the floor is refused
@e2e exclude Covered by SessionServiceTest on the fixture case type; the journey belongs to the bezwaar package.
- **GIVEN** a session `hearing` with an inspection floor of 7 days
- **WHEN** it is planned 4 days ahead
- **THEN** planning MUST be refused and no session stored

#### Scenario: A planned hearing fits the session schema
@e2e exclude Covered by SessionServiceTest, which validates the stored record against the real hearingSession schema.
- **GIVEN** a session `hearing` planned 9 days ahead with one applicant
- **WHEN** it is stored
- **THEN** it MUST carry `sessionKey: hearing` and the inspection deadline 7 days before the start, and validate against `hearingSession`

### Requirement: Attendance and minutes follow the session definition (REQ-CSS-3)
Attendance entries MUST be added freely until the grace window after the start
has passed; after it, every entry MUST carry a `correctionReason`, and none is
written when one lacks it. Closing a session MUST write the executed status.
When the session requires minutes it MUST be refused without a summary or a
document; when it requires recording consent an audio recording MUST be refused
unless consent is `granted`. A session stored without a `sessionKey` MUST take
the case type's only session, and MUST be refused when the case type declares
none or several.

#### Scenario: A late attendance entry without a reason changes nothing
@e2e exclude Covered by SessionServiceTest with a fixed clock.
- **GIVEN** a session with a grace window of 1 hour, started 61 minutes ago
- **WHEN** an attendance entry without a correction reason is added
- **THEN** it MUST be refused and the stored attendance MUST be unchanged

### Requirement: A party waives only a session declared waivable (REQ-CSS-4)
Waiving MUST need a reason and MUST be refused for a session not declared
`waivable`. A waived session MUST carry `hearingWaived: true`, the reason and
the declared waived status.

#### Scenario: A session that cannot be waived
@e2e exclude Covered by SessionServiceTest.
- **GIVEN** a session `site-visit` not declared waivable
- **WHEN** a party waives it
- **THEN** the waiver MUST be refused

### Requirement: Sessions show in Calendar through the session schema (REQ-CSS-5)
The session schema MUST declare `configuration.calendarProvider` with
`dtstart: scheduledDate` and the session `title` as the event title, so
OpenRegister's virtual calendar shows each session under the viewer's own
rights. No dossiq class writes calendar events for a session.

#### Scenario: The session schema is a calendar
@e2e exclude Configuration only, covered by SessionServiceTest against the merged register; the live calendar check is owed with the bezwaar package journey.
- **GIVEN** the merged dossiq register
- **WHEN** the `hearingSession` schema is read
- **THEN** its `calendarProvider` MUST be enabled on `scheduledDate`
