## ADDED Requirements

### Requirement: Hearing Session Entity and Schema Registration

The system SHALL register a `hearingSession` schema in the Procest OpenRegister configuration as a Schema.org `schema:Event`. The schema SHALL declare the properties `case`, `scheduledDate`, `location`, `videoCallUrl`, `chairperson`, `members`, `invitees`, `inspectionAvailableFrom`, `inspectionDeadline`, `attendance`, `minutesSummary`, `minutesDocument`, `audioRecording`, `recordingConsent`, `followUpQuestions`, `status`, `hearingWaived`, and `waiverReason`. The required-property list SHALL be exactly `[case, scheduledDate, chairperson, invitees, inspectionAvailableFrom, inspectionDeadline, status]`. The `status` enum SHALL be exactly `[gepland, uitgenodigd, dossier_beschikbaar, uitgevoerd, geannuleerd, afgezien]`. A hearing session SHALL be the only entity that owns a hoorzitting lifecycle and SHALL always be linked to exactly one bezwaar case.

**Feature tier**: V1
**Schema.org type**: `schema:Event`
**CMMN mapping**: HumanTask within bezwaar CasePlanModel
**AWB reference**: Art. 7:2 (right to be heard)

#### Scenario: Schema is registered after app install

- **WHEN** the Procest app is installed or updated via the repair step
- **THEN** the `hearingSession` schema SHALL exist in the Procest register
- **AND** the schema SHALL enforce required properties `case`, `scheduledDate`, `chairperson`, `invitees`, `inspectionAvailableFrom`, `inspectionDeadline`, `status`
- **AND** the `status` enum SHALL be exactly `[gepland, uitgenodigd, dossier_beschikbaar, uitgevoerd, geannuleerd, afgezien]`

#### Scenario: HearingSession rejects unknown status

- **GIVEN** a caller posts a hearingSession object with `status = "verdaagd"` (not in the enum)
- **WHEN** the create request is validated
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** no hearingSession SHALL be persisted

### Requirement: Hearing Scheduling and Waiver

The system SHALL support scheduling a hearing for a bezwaar case and, alternatively, recording the bezwaarmaker's waiver of the right to be heard under Awb art. 7:3. On scheduling, the hearingSession SHALL be created with `status = gepland`. On waiver, the hearingSession SHALL be created with `status = afgezien` and `hearingWaived = true` and a non-empty `waiverReason`. Both transitions SHALL append an audit entry to the parent case audit trail tagged `awb-art-7:2` (scheduling) or `awb-art-7:3` (waiver).

**Feature tier**: V1
**AWB reference**: Art. 7:2 (hearing), Art. 7:3 (waiver)

#### Scenario: Schedule a hearing for a bezwaar case

- **GIVEN** a bezwaar case BZ-2026-0042 with status "In behandeling"
- **WHEN** the behandelaar schedules a hearing with `scheduledDate = 2026-06-01T10:00:00Z`
- **THEN** a hearingSession SHALL be created with `status = gepland`
- **AND** the case status SHALL transition to "Hoorzitting gepland"
- **AND** the system SHALL validate that `scheduledDate` is at least 5 working days in the future
- **AND** an audit entry tagged `awb-art-7:2` SHALL be appended to the case audit trail

#### Scenario: Bezwaarmaker waives the right to be heard

- **GIVEN** a bezwaar case BZ-2026-0042 in status "In behandeling"
- **WHEN** the behandelaar records a waiver with `waiverReason = "Belanghebbende heeft schriftelijk afgezien van het recht te worden gehoord"`
- **THEN** a hearingSession SHALL be created with `status = afgezien`, `hearingWaived = true`, and the supplied `waiverReason`
- **AND** the case SHALL be able to skip the "Hoorzitting gepland" and "Hoorzitting afgerond" statuses
- **AND** an audit entry tagged `awb-art-7:3` SHALL be appended to the case audit trail

#### Scenario: Waiver cannot be recorded after hearing is completed

- **GIVEN** a hearingSession with `status = uitgevoerd`
- **WHEN** any caller attempts to set `hearingWaived = true` on that hearingSession
- **THEN** the system SHALL reject the update with HTTP 409
- **AND** the response message SHALL be `Hoorzitting heeft reeds plaatsgevonden`
- **AND** the hearingSession status SHALL remain `uitgevoerd`

### Requirement: Invitation Flow with Accessibility Support

The system SHALL send hearing invitations to every entry in `invitees`. Each invitee carries a `channel` enum (`berichtenbox`, `email`, `post`, `in_person`) and an `accessibilityNeeds[]` array (`low_literacy`, `interpreter`, `sign_language`, `physical_access`). Channel selection SHALL prefer Berichtenbox when the bezwaarmaker has a connected MijnOverheid account, fall back to email, and otherwise queue a paper print task. Each accessibility need SHALL produce a documented variant (B1-level Dutch, tolk-booking task, gebarentolk task, or wheelchair-access verification task) and a case audit entry demonstrating Awb art. 2:1 / art. 7:2 reasonable-accommodation duty.

**Feature tier**: V1
**AWB reference**: Art. 2:1 (reasonable accommodation), Art. 7:2 (invitation duty)

#### Scenario: Invitation routed via Berichtenbox for MijnOverheid-connected bezwaarmaker

- **GIVEN** a hearingSession with `status = gepland` whose bezwaarmaker invitee has a connected MijnOverheid account
- **WHEN** the behandelaar triggers the invitation action
- **THEN** the system SHALL queue a Berichtenbox message for that invitee
- **AND** the hearingSession status SHALL change to `uitgenodigd`
- **AND** the invitation SHALL include date, time, location, the subject of the bezwaar, and a notice of the right to bring witnesses per art. 7:8

#### Scenario: Low-literacy invitee gets B1-level template

- **GIVEN** a hearingSession invitee with `accessibilityNeeds = ["low_literacy"]`
- **WHEN** the invitation is rendered
- **THEN** the invitation body SHALL use a B1-level Dutch template
- **AND** the date, time, and location SHALL be repeated in a top callout box
- **AND** an audit entry tagged `accessibility-b1` SHALL be appended to the case audit trail

#### Scenario: Interpreter-required invitee triggers tolk-booking task

- **GIVEN** a hearingSession invitee with `accessibilityNeeds = ["interpreter"]` and `requestedLanguage = "Tigrinya"`
- **WHEN** the invitation is sent
- **THEN** the system SHALL create a "Tolk regelen" task on the case with the requested language
- **AND** the invitation block SHALL state that an interpreter has been requested
- **AND** an audit entry tagged `accessibility-interpreter` SHALL be appended to the case audit trail

### Requirement: Inspection of File Before Hearing

The system SHALL enforce Awb art. 7:4 lid 2 by guaranteeing that the bezwaardossier is available for inspection by the bezwaarmaker and their gemachtigde at least seven calendar days before the hearing. On hearingSession creation the system SHALL compute `inspectionDeadline = scheduledDate − 7 days`. If a later edit moves `scheduledDate` closer than seven days from today, the save SHALL be rejected. Documents flagged `confidential` under Awb art. 7:6 SHALL be excluded from the inspection bundle but listed as withheld with a reason placeholder. Every inspection access SHALL be logged with `actor`, `documentId`, `accessedAt`, and `purpose = "art-7:4-inspection"`.

**Feature tier**: V1
**AWB reference**: Art. 7:4 (inspection of file), Art. 7:6 (confidential documents)

#### Scenario: Inspection deadline is auto-computed

- **WHEN** a hearingSession is created with `scheduledDate = 2026-06-15`
- **THEN** `inspectionDeadline` SHALL be set to `2026-06-08`
- **AND** `inspectionAvailableFrom` SHALL be no later than `inspectionDeadline`

#### Scenario: Rescheduling closer than 7 days is blocked

- **GIVEN** a hearingSession with `scheduledDate = 2026-06-15` and today is `2026-06-10`
- **WHEN** the behandelaar attempts to update `scheduledDate` to `2026-06-12`
- **THEN** the system SHALL reject the update with HTTP 422
- **AND** the response message SHALL be `Inzagetermijn (art. 7:4) wordt geschonden — minimaal 7 dagen voor de hoorzitting`

#### Scenario: Confidential document is withheld from inspection bundle

- **GIVEN** a bezwaardossier document flagged `confidential` under art. 7:6
- **WHEN** the bezwaarmaker requests the inspection bundle
- **THEN** the document SHALL NOT be included
- **AND** a placeholder entry SHALL list the document title with the reason `Document onthouden op grond van art. 7:6 Awb`
- **AND** the withholding event SHALL be logged on the inspection trail

### Requirement: Attendee List and Attendance Tracking

The system SHALL track who was invited and who actually attended. The `invitees` array SHALL be set at scheduling time and frozen at invitation; the `attendance` array SHALL be captured during or immediately after the hearing with `{invitee, present, arrivalTime}` entries. Attendance entries SHALL be appendable up to one hour after the hearing concludes; afterwards the list becomes read-only and any correction SHALL require a documented audit-trail entry.

**Feature tier**: V1
**AWB reference**: Art. 7:8 (witnesses), Art. 7:7 (verslag)

#### Scenario: Capture attendance after the hearing

- **GIVEN** a hearingSession with three invitees (bezwaarmaker, gemachtigde, primair beslisser)
- **WHEN** the chairperson records that bezwaarmaker and gemachtigde attended, primair beslisser did not
- **THEN** the `attendance` array SHALL contain three entries with `present = true|false` per invitee
- **AND** the absent entry SHALL still be recorded for audit completeness
- **AND** an arrival timestamp SHALL be captured for each present invitee

#### Scenario: Late correction requires audit reason

- **GIVEN** a hearingSession with `status = uitgevoerd` whose attendance was captured more than one hour ago
- **WHEN** the behandelaar attempts to mark an invitee as present without a correction reason
- **THEN** the system SHALL reject the update with HTTP 422
- **AND** the response message SHALL be `Aanwezigheidscorrectie vereist toelichting in audit trail`

### Requirement: Minutes Capture (Text and Optional Audio)

The system SHALL require that a hearingSession transitioning to `status = uitgevoerd` carries at least one of `minutesSummary` (non-empty text) or `minutesDocument` (uploaded verslag file). Audio recording (`audioRecording`) SHALL be optional and SHALL only be accepted when `recordingConsent = granted` has been recorded for the bezwaarmaker. A consent denial SHALL be persisted and SHALL block subsequent audio upload attempts. Recorded audio files SHALL inherit the parent case's retention regime.

**Feature tier**: V1
**AWB reference**: Art. 7:7 (verslag duty), AVG/GDPR Art. 6 (lawful basis for audio)

#### Scenario: Minutes summary satisfies the verslag duty

- **GIVEN** a hearingSession with `status = uitgenodigd` after the hearing concludes
- **WHEN** the chairperson sets `minutesSummary = "Bezwaarmaker lichtte gronden toe, primair beslisser reageerde..."` and marks the session `uitgevoerd`
- **THEN** the transition SHALL be accepted
- **AND** the case status SHALL transition to "Hoorzitting afgerond"

#### Scenario: Cannot mark uitgevoerd without verslag

- **GIVEN** a hearingSession with empty `minutesSummary` and no `minutesDocument`
- **WHEN** the chairperson attempts to set `status = uitgevoerd`
- **THEN** the system SHALL reject the update with HTTP 422
- **AND** the response message SHALL be `Verslag (art. 7:7) ontbreekt — vul minutesSummary of upload minutesDocument`

#### Scenario: Audio recording requires explicit consent

- **GIVEN** a hearingSession with `recordingConsent = denied`
- **WHEN** any caller attempts to upload `audioRecording`
- **THEN** the system SHALL reject the upload with HTTP 403
- **AND** the response message SHALL be `Bezwaarmaker heeft geen toestemming gegeven voor audio-opname`
- **AND** a denial audit entry SHALL be appended to the case audit trail

### Requirement: Follow-Up Questions After the Hearing

The system SHALL allow the chairperson to append follow-up questions to a completed hearingSession. Each entry SHALL carry `{question, askedTo, deadline}`. Outstanding follow-up questions SHALL surface on the bezwaar dashboard widget until each is answered or marked withdrawn. Answers SHALL attach to the case dossier as `hearingFollowUp` documents and SHALL link back to the originating question.

**Feature tier**: V1
**AWB reference**: Art. 7:9 (renewed hearing on new facts) — informational

#### Scenario: Add a follow-up question

- **GIVEN** a hearingSession with `status = uitgevoerd`
- **WHEN** the chairperson appends a `followUpQuestions` entry `{ question: "Aanvullende onderbouwing van financiële schade?", askedTo: <bezwaarmakerId>, deadline: "2026-06-22" }`
- **THEN** the entry SHALL be persisted on the hearingSession
- **AND** the dashboard widget for the parent case SHALL list the outstanding question with its deadline

#### Scenario: Outstanding follow-up triggers dashboard surfacing

- **GIVEN** a hearingSession with one follow-up question whose `deadline` is in three days and no answer yet
- **WHEN** the behandelaar opens the bezwaar dashboard
- **THEN** the case SHALL appear in the "Openstaande hoorzitting-vragen" widget
- **AND** the widget SHALL show the question text, askedTo party, and remaining days

### Requirement: Legal Compliance Audit Hooks

The system SHALL write structured audit entries for every legally relevant event on a hearingSession so that beroep dossier export can demonstrate Awb compliance without manual reconstruction. The audit entries SHALL be tagged with the applicable Awb article and SHALL include `actor`, `timestamp`, `event`, and event-specific payload. The minimum set of tagged events SHALL be: status transitions (`awb-art-7:2`), waiver (`awb-art-7:3`), invitation timestamps (`awb-art-7:2`), inspection access (`awb-art-7:4`), confidential withholding (`awb-art-7:6`), verslag finalization (`awb-art-7:7`), and recording consent / denial (`avg-art-6`).

**Feature tier**: V1
**AWB reference**: Art. 7:2, 7:3, 7:4, 7:6, 7:7; AVG/GDPR Art. 6

#### Scenario: Status transition writes tagged audit entry

- **GIVEN** a hearingSession transitioning from `gepland` to `uitgenodigd`
- **WHEN** the transition is committed
- **THEN** the case audit trail SHALL gain an entry with `tag = "awb-art-7:2"`, `event = "invitation_sent"`, `actor = <behandelaarUID>`, and a timestamp

#### Scenario: Inspection access is logged per document

- **GIVEN** a bezwaarmaker viewing three documents in the inspection bundle
- **WHEN** each document is opened
- **THEN** the inspection trail SHALL gain three entries each carrying `tag = "awb-art-7:4"`, `actor`, `documentId`, `accessedAt`, and `purpose = "art-7:4-inspection"`

#### Scenario: Beroep export includes the compliance audit set

- **GIVEN** a bezwaar case with one completed hearingSession (`uitgevoerd`) and a waiver-less full procedure
- **WHEN** the dossier export action is triggered for beroep submission
- **THEN** the export SHALL include the structured audit entries tagged `awb-art-7:2`, `awb-art-7:4`, `awb-art-7:7`, and any `avg-art-6` consent entries
- **AND** the export SHALL link each entry to its hearingSession and document references
