# bezwaar-awb-audit-trail Specification

## Purpose
Where the Awb and AVG entries of a bezwaar procedure are kept. Each entry is a row on OpenRegister's hash-chained audit trail of the record it describes, with its tag, actor, time and payload. The `auditTrail` arrays written before this moved are kept as a frozen record and copied across once.

## Requirements

### Requirement: An Awb entry is a row on OpenRegister's audit trail of the record it describes (REQ-BAT-001)

Every entry the bezwaar procedure records SHALL be written through OpenRegister's
`AuditTrailMapper::createAuditTrailEntry()` against the `ObjectEntity` it describes (a
`hearingSession` or a `bacAdviceRequest`), with action `dossiq.bezwaar.<event>` and context
`{event, tag, actor, at, payload}` in that key order, `tag` omitted for an untagged entry. The actor
SHALL come from the session, never from the caller, and SHALL be `system` without a session. No code
SHALL write the `auditTrail` property of either schema.

#### Scenario: A scheduled hearing is recorded under Awb art. 7:2
- **GIVEN** a bezwaar that moves to "hearing planned", and a signed-in handler
- **WHEN** `BezwaarHearingScheduledListener` seeds the default hearing
- **THEN** OpenRegister's trail of the new `hearingSession` SHALL hold one row with action `dossiq.bezwaar.hearing-scheduled`
- **AND** its context SHALL carry `tag` `awb-art-7:2`, the handler's uid as `actor`, `at`, and the case, scheduled date and inspection deadline as `payload`
- **AND** the saved `hearingSession` SHALL carry no new `auditTrail` entry

#### Scenario: A late attendance correction is recorded under Awb art. 7:7 with its reason
- **GIVEN** a hearing whose one-hour grace window has passed
- **WHEN** `BezwaarHearingController` records an attendance correction with a reason
- **THEN** the session's trail SHALL hold a `dossiq.bezwaar.attendance-late-correction` row tagged `awb-art-7:7` carrying the invitee, presence and reason

#### Scenario: A refused audio upload is recorded under AVG art. 6
- **GIVEN** minutes with an audio recording and `recordingConsent` not `granted`
- **WHEN** `HearingService::addMinutes()` runs
- **THEN** the upload SHALL be refused
- **AND** the session's trail SHALL hold a `dossiq.bezwaar.audio-upload-denied` row tagged `avg-art-6`

#### Scenario: The actor is never taken from the caller
- **GIVEN** a payload that carries an `actor` key naming another user
- **WHEN** an entry is recorded in a session of user `handler-1`
- **THEN** the row's actor SHALL be `handler-1`

### Requirement: The advice request entries reach the advice request's history screen (REQ-BAT-002)

The entries of a `bacAdviceRequest` SHALL appear in the History sidebar tab of
`BezwaarAdviceRequestDetail`, which reads OpenRegister's trail for that object.

#### Scenario: The history tab shows the committee assignment
- **GIVEN** a bezwaar whose advice is requested, with a default committee configured
- **WHEN** a handler opens the new advice request and its History tab
- **THEN** the tab SHALL list the `dossiq.bezwaar.panel-member-added` entry with its actor and time

### Requirement: No bezwaar act stands without its entry (REQ-BAT-003)

When an entry cannot be written, because OpenRegister is absent, the object cannot be resolved, or
the write throws, the act SHALL NOT be reported as done.

- An act that creates a record (`schedule()`, `waive()`, `assignToCommittee()`) SHALL save the record,
  write the entry, and on a failed entry delete the record it just created and raise an error.
- An act that changes a record (`recordAttendance()`, `addMinutes()`, `transitionAdviceStatus()`)
  SHALL write the entry first and change the record after. When the change then fails, it SHALL
  write a `dossiq.bezwaar.<event>-not-applied` entry and raise an error.
- A refusal (`audio-upload-denied`, `independence-check-failed`) SHALL refuse whether or not its
  entry was written, and SHALL log the full entry at error level when it was not.
- `recordCouncilDeviation()` SHALL report a failed entry to its listener, which SHALL log it at error
  level with the full entry; it SHALL NOT be swallowed silently.

#### Scenario: A hearing whose entry cannot be written is not scheduled
- **GIVEN** OpenRegister's audit trail throws on write
- **WHEN** `HearingService::schedule()` runs
- **THEN** it SHALL raise an error
- **AND** the `hearingSession` it saved SHALL be deleted

#### Scenario: A signed advice is recorded before the status moves
- **GIVEN** an advice request in deliberation with its advice content complete
- **WHEN** the chair moves it to `advice-issued` and the status write then fails
- **THEN** the trail SHALL hold `dossiq.bezwaar.advice-signed-by-chair` followed by `dossiq.bezwaar.advice-signed-by-chair-not-applied`
- **AND** the service SHALL raise an error

### Requirement: Entries already in the arrays are copied across once and kept (REQ-BAT-004)

A repair step SHALL copy every entry of every `hearingSession.auditTrail` and
`bacAdviceRequest.auditTrail` onto OpenRegister's trail of that object, in array order, with action
`dossiq.bezwaar.<event>`, actor `system`, and context holding the original `event`, `tag`, `actor`,
`at` and `payload` plus `migratedFrom: auditTrail` and `migratedIndex`. It SHALL NOT change or delete
the array. Run twice, it SHALL write nothing the second time. An object whose entries it cannot write
SHALL be reported by uuid, and the repair SHALL go on with the next object.

#### Scenario: Three old entries arrive once, in order, with their original time and actor
- **GIVEN** a `hearingSession` whose `auditTrail` holds three entries written by `handler-1` in June
- **WHEN** the repair step runs twice
- **THEN** the session's trail SHALL hold exactly three copied rows, `migratedIndex` 0, 1 and 2
- **AND** each row's context SHALL carry the original `at` and `actor: handler-1`
- **AND** the session's `auditTrail` array SHALL be byte-for-byte unchanged
