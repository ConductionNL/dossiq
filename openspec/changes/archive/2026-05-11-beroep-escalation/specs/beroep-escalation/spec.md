## ADDED Requirements

### Requirement: Beroep Entity and Schema Registration

The system SHALL register a `beroep` schema in the Procest OpenRegister configuration. The schema SHALL declare the properties `case`, `sourceBezwaar`, `contestedDecision`, `courtReference`, `responsibleChamber`, `competentCourt`, `appellantFilingDate`, `appellantNotifiedDate`, `filingDeadline`, `voorzieningRequested`, `judgmentOutcome`, `judgmentDate`, `judgmentDocument`, `cascadeAction`, and `cascadeBezwaarCase`. The required-property list SHALL be exactly `[case, sourceBezwaar, contestedDecision, appellantFilingDate]`. The `responsibleChamber` enum SHALL be exactly `[enkelvoudig, meervoudig, voorzieningenrechter]`. The `judgmentOutcome` enum SHALL be exactly `[vernietigd, in_stand_gelaten, niet_ontvankelijk, ongegrond, gegrond_rechtsgevolgen_in_stand, ingetrokken, schikking]`. The `cascadeAction` enum SHALL be exactly `[reopen_bezwaar, new_primary_decision, none]`. The Schema.org type SHALL be `schema:LegalAction`.

**Feature tier**: V1
**Schema.org type**: `schema:LegalAction`
**AWB reference**: Art. 8:1 (beroep bij de rechtbank)

#### Scenario: Schema is registered after app install

- **WHEN** the Procest app is installed or updated via the repair step
- **THEN** the `beroep` schema SHALL exist in the Procest register
- **AND** the schema SHALL enforce the required properties `case`, `sourceBezwaar`, `contestedDecision`, `appellantFilingDate`
- **AND** the `judgmentOutcome` enum SHALL be exactly `[vernietigd, in_stand_gelaten, niet_ontvankelijk, ongegrond, gegrond_rechtsgevolgen_in_stand, ingetrokken, schikking]`

#### Scenario: Beroep rejects unknown judgment outcome

- **GIVEN** a handler posts a judgment update with `judgmentOutcome = "afgewezen"` (not in the enum)
- **WHEN** the update is validated
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** no judgment SHALL be persisted

### Requirement: Filing Deadline Tracking and Dwingende Status Flip

The system SHALL compute `filingDeadline` as `contestedDecision.effectiveDate + P6W` (Awb art. 6:7, 6:8) on beroep creation and SHALL flag `latefilingNotice = true` when `appellantFilingDate > filingDeadline`. The system SHALL NEVER itself decide whether late filing is excusable (`verschoonbare termijnoverschrijding`); that determination is reserved for the rechtbank. While the beroep status is not terminal (`Afgehandeld`, `Ingetrokken`, `Schikking`), the system SHALL mark the source bezwaar with a derived `dwingendeMarker = true` flag visible in dashboards, and the bezwaar case SHALL be read-only.

**Feature tier**: V1
**AWB reference**: Art. 6:7, 6:8

#### Scenario: Compute filing deadline on beroep creation

- **GIVEN** a beslissing op bezwaar with `effectiveDate = 2026-04-01`
- **WHEN** a beroep is created with `contestedDecision` referencing that beslissing
- **THEN** the beroep's `filingDeadline` SHALL be `2026-05-13` (effectiveDate + 6 weeks)

#### Scenario: Late filing flagged but never refused

- **GIVEN** a beroep with `filingDeadline = 2026-05-13` and `appellantFilingDate = 2026-05-30`
- **WHEN** the handler saves the beroep
- **THEN** the system SHALL set `latefilingNotice = true`
- **AND** the system SHALL NOT refuse or auto-close the beroep — the rechtbank is the only authority on timeliness

#### Scenario: Source bezwaar flips to dwingendeMarker

- **GIVEN** a bezwaar BZ-2026-0042 with `status = Afgehandeld`
- **WHEN** a beroep is created with `sourceBezwaar = BZ-2026-0042`
- **THEN** the bezwaar's derived `dwingendeMarker` SHALL be `true`
- **AND** the bezwaar case SHALL be read-only while the beroep is not in a terminal status
- **AND** dashboards SHALL surface BZ-2026-0042 under "Bezwaak in beroep"

### Requirement: Court Reference and Responsible Chamber

The system SHALL allow recording a court-assigned `courtReference` (rechtbank zaaknummer), the `competentCourt` (name of the rechtbank), and the `responsibleChamber` (`enkelvoudig`, `meervoudig`, or `voorzieningenrechter`). These fields SHALL be optional at beroep creation and SHALL be populated by the handler when the rechtbank communicates them. The case detail header SHALL surface `competentCourt` + `courtReference` once present.

**Feature tier**: V1

#### Scenario: Handler records court reference

- **GIVEN** a beroep BR-2026-0015 with no `courtReference`
- **WHEN** the rechtbank assigns case number `UTR 26/1234` and the handler records it
- **THEN** `beroep.courtReference` SHALL equal `UTR 26/1234`
- **AND** the case detail header SHALL display "Rechtbank Midden-Nederland — UTR 26/1234"

#### Scenario: Chamber upgrade from enkelvoudig to meervoudig

- **GIVEN** a beroep with `responsibleChamber = enkelvoudig`
- **WHEN** the rechtbank decides the case requires a meervoudige kamer and the handler updates the field
- **THEN** `responsibleChamber` SHALL transition to `meervoudig`
- **AND** the change SHALL be recorded in the OpenRegister audit trail

### Requirement: File Inspection Request Fulfillment

The system SHALL record file inspection requests issued by the rechtbank under Awb art. 8:42 (`op de zaak betrekking hebbende stukken`). For each request the system SHALL store `requestedAt`, a computed `deadline = requestedAt + P4W`, `submittedAt` once fulfilled, and `dossierBundle` referencing the compiled bundle. The system SHALL surface a warning when `now > deadline AND submittedAt IS NULL`. The system SHALL NOT itself generate the bundle — bundle assembly is performed by Juridische Zaken via existing dossier tooling — but it SHALL record the linkage.

**Feature tier**: V1
**AWB reference**: Art. 8:42

#### Scenario: Record file inspection request

- **GIVEN** a beroep BR-2026-0015
- **WHEN** the rechtbank issues a file inspection request on 2026-06-01 and the handler records it
- **THEN** `beroep.fileInspectionRequest.requestedAt` SHALL equal `2026-06-01`
- **AND** `beroep.fileInspectionRequest.deadline` SHALL equal `2026-06-29` (requestedAt + 4 weeks)
- **AND** the case SHALL appear in the "Stukken aan rechtbank" dashboard

#### Scenario: File inspection deadline warning

- **GIVEN** a file inspection request with `deadline = 2026-06-29` and `submittedAt = NULL`
- **WHEN** the current date is `2026-06-27`
- **THEN** the system SHALL display a warning "Termijn 8:42 verstrijkt over 2 dagen" on the case
- **AND** the case SHALL appear in the overdue/at-risk dashboard section

### Requirement: Judgment Outcome Registration

The system SHALL allow the handler to record the rechtbank's uitspraak by setting `judgmentOutcome`, `judgmentDate`, and `judgmentDocument`. The system SHALL NOT itself interpret or paraphrase the ruling; it SHALL persist only the categorical outcome plus the uploaded ruling document. Once `judgmentOutcome` is set, the beroep case status SHALL transition to "Uitspraak ontvangen".

**Feature tier**: V1

#### Scenario: Record vernietigd judgment

- **GIVEN** a beroep BR-2026-0015 with no `judgmentOutcome`
- **WHEN** the handler records `judgmentOutcome = vernietigd`, `judgmentDate = 2026-09-15`, and uploads the uitspraak as `judgmentDocument`
- **THEN** the beroep case status SHALL transition to "Uitspraak ontvangen"
- **AND** the audit trail SHALL contain a single entry for the judgment registration

#### Scenario: Schikking outcome closes beroep without ruling

- **GIVEN** a beroep BR-2026-0021 that settles out of court
- **WHEN** the handler records `judgmentOutcome = schikking` and `judgmentDate = 2026-08-01`
- **THEN** the beroep case status SHALL transition to "Schikking"
- **AND** `judgmentDocument` SHALL remain optional — settlement minutes are not legally required to be uploaded

### Requirement: Cascade Back Into Procest Workflow

The system SHALL evaluate `cascadeAction` based on the judgment outcome plus handler input. When `judgmentOutcome = vernietigd` AND the ruling orders a new bezwaar decision, the handler SHALL be able to trigger `cascadeAction = reopen_bezwaar`, which SHALL create a new bezwaar case forked from `sourceBezwaar` with status "In behandeling", `cascadeBezwaarCase` populated on the beroep, a link back from the new bezwaar to the beroep, and a court-imposed deadline carried on the new case. When `cascadeAction = none`, the source bezwaar's `dwingendeMarker` SHALL be cleared and the bezwaar SHALL return to its terminal status.

**Feature tier**: V1

#### Scenario: Vernietigd cascades into reopened bezwaar

- **GIVEN** a beroep BR-2026-0015 with `judgmentOutcome = vernietigd`, `sourceBezwaar = BZ-2026-0042`
- **WHEN** the handler triggers `cascadeAction = reopen_bezwaar` with a court-imposed deadline of `2026-12-01`
- **THEN** a new bezwaar case BZ-2026-0042-R1 SHALL be created with status "In behandeling"
- **AND** `BZ-2026-0042-R1.afhandelDeadline` SHALL equal `2026-12-01`
- **AND** `beroep.cascadeBezwaarCase` SHALL reference BZ-2026-0042-R1
- **AND** BZ-2026-0042-R1 SHALL carry a link back to BR-2026-0015

#### Scenario: In stand gelaten cascade clears dwingende marker

- **GIVEN** a beroep BR-2026-0021 with `judgmentOutcome = in_stand_gelaten`, `sourceBezwaar = BZ-2026-0050`, and `BZ-2026-0050.dwingendeMarker = true`
- **WHEN** the handler triggers `cascadeAction = none`
- **THEN** `BZ-2026-0050.dwingendeMarker` SHALL be cleared
- **AND** BZ-2026-0050 SHALL return to its terminal status (`Afgehandeld`)
- **AND** the beroep case status SHALL transition to "Afgehandeld"

### Requirement: Beroep Audit and Immutability

The system SHALL leverage OpenRegister's per-save audit trail to record every change to a beroep object. After `appellantFilingDate` is first set, `sourceBezwaar` and `contestedDecision` SHALL be immutable — attempts to modify them SHALL be rejected with a validation error. All other fields remain editable but each edit SHALL produce an audit entry capturing actor, timestamp, and changed properties.

**Feature tier**: V1

#### Scenario: Cannot change sourceBezwaar after filing

- **GIVEN** a beroep with `appellantFilingDate = 2026-05-10` and `sourceBezwaar = BZ-2026-0042`
- **WHEN** any client attempts to PATCH `sourceBezwaar = BZ-2026-0099`
- **THEN** the system SHALL reject the request with a validation error
- **AND** the beroep SHALL retain `sourceBezwaar = BZ-2026-0042`

#### Scenario: Audit trail captures judgment registration

- **GIVEN** a beroep with no `judgmentOutcome`
- **WHEN** the handler records `judgmentOutcome = ongegrond`
- **THEN** the OpenRegister audit trail SHALL contain one entry with actor = the handler's UID, timestamp, and changed property `judgmentOutcome`

### Requirement: Authorization

The system SHALL restrict edit rights on a beroep object to users holding the `Behandelaar bezwaar` or `Juridische Zaken` role on the source bezwaar. Read access SHALL be granted to any user who has read access to the source bezwaar. Server-side identity SHALL be derived from `IUserSession`; any UID supplied in the request body SHALL be ignored.

**Feature tier**: V1

#### Scenario: Non-authorized user cannot edit beroep

- **GIVEN** a beroep BR-2026-0015 with source bezwaar BZ-2026-0042
- **AND** user P. Janssen has no role on BZ-2026-0042
- **WHEN** P. Janssen attempts to PATCH `courtReference`
- **THEN** the system SHALL reject the request with HTTP 403
- **AND** no audit entry SHALL be written

#### Scenario: Authorized user edit recorded with session UID

- **GIVEN** user K. Vermeulen is authenticated and holds `Behandelaar bezwaar` on BZ-2026-0042
- **WHEN** K. Vermeulen PATCHes `courtReference = UTR 26/1234` and the request body also contains `_actor = "system"`
- **THEN** the audit entry SHALL record actor = `k.vermeulen` (the session UID)
- **AND** the `_actor` body field SHALL be ignored
