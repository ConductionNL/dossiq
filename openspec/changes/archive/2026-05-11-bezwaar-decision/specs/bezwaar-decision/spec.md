## ADDED Requirements

### Requirement: Beslissing op Bezwaar Entity and Schema Registration

The system SHALL register a `bezwaarBesluit` schema (or equivalent decision extension) in the Procest OpenRegister configuration to record the beslissing op bezwaar. The schema SHALL declare the properties `case`, `contestedDecision`, `disposition`, `motivering`, `heroverwegingExNunc`, `advisoryReport`, `followsAdvice`, `deviationReason`, `replacementDecision`, `appealNotice`, `proceskosten`, `decisionDate`, `effectiveDate`, `decisionMaker`, `decisionDocument`, `publishedAt`, and `notifiedRecipients`. The required-property list SHALL be exactly `[case, contestedDecision, disposition, motivering, decisionDate, effectiveDate]`. The Schema.org type SHALL be `schema:Action`. The ZGW mapping SHALL be `besluit` with `besluittype` "Beslissing op bezwaar". A bezwaarBesluit is the single, legally binding final act of a bezwaarprocedure and SHALL be the only entity carrying the Awb 7:11 outcome.

**Feature tier**: V1
**Schema.org type**: `schema:Action`
**ZGW mapping**: `besluit` with `besluittype` "Beslissing op bezwaar"
**AWB reference**: Art. 7:11, 7:12

#### Scenario: Schema is registered after app install

- **WHEN** the Procest app is installed or updated via the repair step
- **THEN** the `bezwaarBesluit` schema SHALL exist in the Procest register
- **AND** the schema SHALL enforce the required properties `case`, `contestedDecision`, `disposition`, `motivering`, `decisionDate`, `effectiveDate`
- **AND** the `disposition` enum SHALL be the canonical five-value Awb 7:11 enum

#### Scenario: BezwaarBesluit rejects unknown disposition

- **GIVEN** the beslisser posts a new bezwaarBesluit with `disposition = "deels_gegrond"` (legacy value, not in the canonical enum)
- **WHEN** the create request is validated
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** no bezwaarBesluit SHALL be persisted

### Requirement: Canonical Awb 7:11 Disposition Enum

The `disposition` property SHALL be a closed enum with exactly five values that map to the legally recognized Awb Art. 7:11 outcomes: `niet_ontvankelijk`, `ongegrond`, `gegrond_handhaven`, `gegrond_herroepen`, `gegrond_wijzigen`. No additional values SHALL be accepted. Each value SHALL drive downstream rules for replacement besluit, proceskostenvergoeding eligibility, and case status transition.

**Feature tier**: V1
**AWB reference**: Art. 7:11

| Value | Meaning |
|-------|---------|
| `niet_ontvankelijk` | Bezwaar inadmissible (e.g., termijn overschreden, geen belanghebbende, geen besluit) |
| `ongegrond` | Bezwaar unfounded — primair besluit upheld unchanged |
| `gegrond_handhaven` | Bezwaar partially founded; primair besluit upheld with new/better motivation |
| `gegrond_herroepen` | Bezwaar founded; primair besluit herroepen, optionally replaced |
| `gegrond_wijzigen` | Bezwaar founded; primair besluit gewijzigd; replacement besluit required |

#### Scenario: Each canonical disposition is accepted

- **WHEN** a bezwaarBesluit is created with `disposition` set to any of `niet_ontvankelijk`, `ongegrond`, `gegrond_handhaven`, `gegrond_herroepen`, `gegrond_wijzigen`
- **THEN** the schema SHALL accept the value and persist the object
- **AND** the system SHALL route the case status transition based on the disposition value

#### Scenario: Non-canonical disposition is rejected

- **WHEN** a bezwaarBesluit is created with `disposition = "gegrond"` (the unqualified legacy value)
- **THEN** the schema SHALL reject the request
- **AND** the response SHALL list the five accepted values

### Requirement: Mandatory Fields per Outcome

The system SHALL enforce outcome-conditional mandatory fields on bezwaarBesluit. When `disposition = gegrond_wijzigen`, a `replacementDecision` reference SHALL be required. When `disposition ∈ {ongegrond, gegrond_handhaven}`, a `replacementDecision` SHALL NOT be set. When `disposition = niet_ontvankelijk`, the `motivering` SHALL cite a specific Awb ontvankelijkheidsground (Art. 6:5, 6:6, 6:7, or another applicable article). When `disposition ∈ {gegrond_herroepen, gegrond_wijzigen}` and the bezwaarmaker requested proceskostenvergoeding, the `proceskosten.awarded` field SHALL be explicitly set.

**Feature tier**: V1
**AWB reference**: Art. 7:11, 7:12, 7:15

#### Scenario: gegrond_wijzigen requires replacementDecision

- **GIVEN** a bezwaarBesluit draft with `disposition = gegrond_wijzigen` and no `replacementDecision`
- **WHEN** the beslisser attempts to publish the bezwaarBesluit
- **THEN** the system SHALL reject the publication with error "replacementDecision is required when disposition is gegrond_wijzigen"
- **AND** the bezwaarBesluit SHALL remain in draft state

#### Scenario: ongegrond rejects replacementDecision

- **GIVEN** a bezwaarBesluit draft with `disposition = ongegrond` and a `replacementDecision` reference
- **WHEN** the beslisser attempts to save the bezwaarBesluit
- **THEN** the system SHALL reject the save with error "replacementDecision MUST NOT be set when disposition is ongegrond — primair besluit blijft in stand"

#### Scenario: niet_ontvankelijk requires Awb ground citation in motivering

- **WHEN** a bezwaarBesluit is created with `disposition = niet_ontvankelijk` and `motivering = "te laat"`
- **THEN** the system SHALL surface a warning: "Motivering bevat geen verwijzing naar Awb art. 6:5/6:6/6:7 — voeg de wettelijke grondslag toe"
- **AND** the warning SHALL block transition to `published`

### Requirement: Heroverweging and Motiveringsplicht

The bezwaarBesluit SHALL be based on a complete reconsideration (heroverweging) of the original besluit including ex-nunc facts and circumstances at the moment of beslissing op bezwaar, per Awb Art. 7:11. The `motivering` property SHALL always be required and SHALL be substantive — not merely a procedural recitation — per Awb Art. 7:12. The system SHALL surface a reformatio-in-peius warning when a reconsideration could lead to a worse outcome for the bezwaarmaker than the original besluit.

**Feature tier**: V1
**AWB reference**: Art. 7:11, 7:12

#### Scenario: Ex-nunc reconsideration field is offered

- **WHEN** the behandelaar prepares a bezwaarBesluit
- **THEN** the decision form SHALL include a `heroverwegingExNunc` field with guidance text: "De heroverweging betreft een volledige heroverweging, inclusief feiten en omstandigheden ten tijde van de beslissing op bezwaar"
- **AND** the field SHALL be free-form text but recommended (not strictly required) to fill in

#### Scenario: Reformatio in peius warning

- **WHEN** the reconsideration could lead to a worse outcome for the bezwaarmaker than the primair besluit (e.g., higher fine, stricter condition)
- **THEN** the system SHALL display a warning: "Let op: reformatio in peius — het bezwaar mag in beginsel niet leiden tot een voor de bezwaarmaker nadeliger besluit"
- **AND** the warning SHALL require explicit acknowledgement by the beslisser before the bezwaarBesluit may transition to `published`

#### Scenario: Motivering cannot be empty

- **WHEN** a bezwaarBesluit is created with `motivering = ""` or whitespace-only
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** the error SHALL cite "Awb Art. 7:12 — motiveringsplicht"

### Requirement: Advisory Committee Opinion and Deviation Rationale

When a bezwaarschriftencommissie advisory report (`advisoryReport`) is referenced on the bezwaarBesluit, the system SHALL track whether the decision follows the committee advice via `followsAdvice`. When `followsAdvice = false`, the `deviationReason` SHALL be a required text field and SHALL be included in the generated decision document, per Awb Art. 7:13 lid 7. When no `advisoryReport` is set, the `followsAdvice` and `deviationReason` fields SHALL be unused.

**Feature tier**: V1
**AWB reference**: Art. 7:13 lid 7

#### Scenario: Decision follows committee advice

- **GIVEN** a bezwaarBesluit with `advisoryReport` set to an existing advisory report and `followsAdvice = true`
- **WHEN** the beslisser publishes the bezwaarBesluit
- **THEN** the system SHALL accept the publication
- **AND** `deviationReason` SHALL be ignored if set

#### Scenario: Decision deviates without rationale

- **GIVEN** a bezwaarBesluit with `advisoryReport` set, `followsAdvice = false`, and no `deviationReason`
- **WHEN** the beslisser attempts to publish
- **THEN** the system SHALL reject the publication with error "deviationReason is required when followsAdvice is false (Awb Art. 7:13 lid 7)"
- **AND** the bezwaarBesluit SHALL remain in draft state

#### Scenario: Deviation rationale appears in generated document

- **GIVEN** a bezwaarBesluit with `followsAdvice = false` and a `deviationReason`
- **WHEN** the bezwaarBesluit is published and a decision document is generated
- **THEN** the document SHALL contain a dedicated section titled "Afwijking van het advies van de bezwaarschriftencommissie"
- **AND** the section SHALL include the verbatim `deviationReason` text

### Requirement: Structured Appeals Notice (Rechtsmiddelenclausule)

The system SHALL store the rechtsmiddelenclausule as a structured `appealNotice` sub-object — not as free-form prose — to guarantee completeness and machine-renderability. The block SHALL carry: `competentCourt`, `beroepTerm` (ISO 8601 duration, default `P6W` per Awb Art. 6:7), `effectiveDate`, `filingMethod` (enum: `digitaal`, `schriftelijk`, `beide`), `filingUrl`, `filingAddress`, `griffierecht`, and `voorlopigeVoorziening` (boolean). When `filingMethod` is `digitaal` or `beide`, a `filingUrl` SHALL be required. When `filingMethod` is `schriftelijk` or `beide`, a `filingAddress` SHALL be required. A bezwaarBesluit SHALL NOT be publishable while any required field of `appealNotice` is missing.

**Feature tier**: V1
**AWB reference**: Art. 3:45, 6:7

#### Scenario: Complete appeal notice block is accepted

- **WHEN** a bezwaarBesluit carries an `appealNotice` with `competentCourt = "Rechtbank Midden-Nederland, sector bestuursrecht"`, `beroepTerm = "P6W"`, `effectiveDate = 2026-05-15`, `filingMethod = "digitaal"`, `filingUrl = "https://mijn.rechtspraak.nl/..."`, and `voorlopigeVoorziening = true`
- **THEN** the bezwaarBesluit SHALL transition to `published` without warnings

#### Scenario: Missing competent court blocks publication

- **GIVEN** a bezwaarBesluit with `appealNotice.competentCourt` empty
- **WHEN** the beslisser attempts to publish
- **THEN** the system SHALL surface the warning "Rechtsmiddelenclausule onvolledig: bevoegde rechter ontbreekt"
- **AND** the publication SHALL be blocked

#### Scenario: Digital filing method requires filingUrl

- **GIVEN** a bezwaarBesluit with `appealNotice.filingMethod = "digitaal"` and `appealNotice.filingUrl` empty
- **WHEN** the beslisser attempts to publish
- **THEN** the system SHALL reject publication with error "filingUrl is required when filingMethod is digitaal"

### Requirement: Proceskostenvergoeding (Awb Art. 7:15)

The system SHALL support recording a proceskostenvergoeding award on the bezwaarBesluit per Awb Art. 7:15. A proceskostenvergoeding SHALL be awardable ONLY when ALL of: (a) `disposition` is `gegrond_herroepen` or `gegrond_wijzigen`, (b) the bezwaarmaker requested proceskostenvergoeding before the beslissing op bezwaar, and (c) the herroeping is attributable to onrechtmatigheid van het primair besluit. The `proceskosten` sub-object SHALL carry `requested` (boolean), `awarded` (boolean), `pointBasis` (BPB-puntensysteem reference), `awardedPoints` (number), `pointValue` (EUR per point), `totalAmount` (computed), `reasoning` (text), and `paymentDate`.

**Feature tier**: V1
**AWB reference**: Art. 7:15

#### Scenario: Proceskostenvergoeding awarded on gegrond_herroepen

- **GIVEN** a bezwaarBesluit with `disposition = gegrond_herroepen`, `proceskosten.requested = true`, `proceskosten.awardedPoints = 2` (1 punt bezwaarschrift + 1 punt hoorzitting), and `proceskosten.pointValue = 624`
- **WHEN** the beslisser publishes the bezwaarBesluit
- **THEN** `proceskosten.totalAmount` SHALL be set to `1248` (2 × 624)
- **AND** the system SHALL include the proceskostenvergoeding in the decision document

#### Scenario: Proceskostenvergoeding rejected on ongegrond

- **GIVEN** a bezwaarBesluit with `disposition = ongegrond` and `proceskosten.awarded = true`
- **WHEN** the beslisser attempts to save the bezwaarBesluit
- **THEN** the system SHALL reject with error "Proceskostenvergoeding niet mogelijk: primair besluit niet herroepen (Awb Art. 7:15 lid 2)"

#### Scenario: Explicit decision required when requested

- **GIVEN** a bezwaarBesluit with `disposition = gegrond_herroepen` and `proceskosten.requested = true`
- **WHEN** the beslisser attempts to publish without setting `proceskosten.awarded`
- **THEN** the system SHALL reject the publication with error "proceskosten.awarded MUST be explicitly set (true or false with reasoning) when the bezwaarmaker requested proceskostenvergoeding"

### Requirement: Decision Deadline (Awb Art. 7:10)

The system SHALL validate the bezwaarBesluit's `decisionDate` against the calculated `afhandelDeadline` on the bezwaar case (owned by the `bezwaar-lifecycle` capability). The base deadline SHALL be 6 weeks from the bezwaar `ontvangstdatum` (Awb Art. 7:10 lid 1). The deadline SHALL be extended by 6 weeks when verdaging is recorded (Art. 7:10 lid 3) and additionally by 4 weeks when a hoorzitting was held (Art. 7:10 lid 2). Opschorting periods SHALL further extend the deadline (Art. 7:10 lid 4). Decisions taken after the deadline SHALL be flagged with a "Beslistermijn overschreden" warning.

**Feature tier**: V1
**AWB reference**: Art. 7:10

#### Scenario: Standard 6-week deadline

- **GIVEN** a bezwaar case with `ontvangstdatum = 2026-03-01` and no verdaging, no hoorzitting, no opschorting
- **WHEN** a bezwaarBesluit is created with `decisionDate = 2026-04-12` (exactly 6 weeks later)
- **THEN** the system SHALL accept the date
- **AND** no "Beslistermijn overschreden" warning SHALL appear

#### Scenario: Deadline extension when hoorzitting held

- **GIVEN** a bezwaar case with `ontvangstdatum = 2026-03-01` and a recorded hoorzitting on 2026-04-05
- **WHEN** a bezwaarBesluit is created with `decisionDate = 2026-05-10` (6 + 4 weeks)
- **THEN** the system SHALL accept the date
- **AND** the calculated `afhandelDeadline` SHALL be 2026-05-10

#### Scenario: Decision after deadline triggers warning

- **GIVEN** a bezwaar case with `afhandelDeadline = 2026-04-12` (no extensions applied)
- **WHEN** a bezwaarBesluit is created with `decisionDate = 2026-04-20`
- **THEN** the system SHALL surface the warning "Beslistermijn overschreden — risico op dwangsom (Awb Art. 4:17) bij ingebrekestelling"
- **AND** the warning SHALL be recorded in the case audit trail

### Requirement: Template-Driven Decision Document Generation

The system SHALL generate the formal beslissing op bezwaar document from a configurable Word/PDF template by merging the bezwaarBesluit entity properties into placeholders, rather than relying on hand-typed letters. The default template SHALL be shipped with Procest; bestuursorganen SHALL be able to configure their own template via app settings. Generation SHALL be triggered automatically on transition from draft to published; the resulting file SHALL be stored as `decisionDocument` in the bezwaardossier (Nextcloud Files) and SHALL be immutable.

**Feature tier**: V1

#### Scenario: Default template is used when no override configured

- **GIVEN** a bezwaarBesluit ready for publication and no bestuursorgaan-specific template configured
- **WHEN** the beslisser publishes the bezwaarBesluit
- **THEN** the system SHALL generate a PDF using the default Procest template
- **AND** the PDF SHALL be saved as `decisionDocument` in the bezwaardossier folder
- **AND** the file SHALL be marked immutable in the OpenRegister audit trail

#### Scenario: Custom template overrides default

- **GIVEN** a bestuursorgaan-specific template configured under app settings
- **WHEN** a bezwaarBesluit owned by that bestuursorgaan is published
- **THEN** the generation SHALL use the bestuursorgaan template, not the default
- **AND** the placeholders SHALL be merged from the bezwaarBesluit entity properties

#### Scenario: Generation fails when required placeholders are missing

- **WHEN** a custom template references a placeholder that does not map to a bezwaarBesluit property
- **THEN** the generation SHALL fail with an explicit error listing the missing placeholder
- **AND** the publication transition SHALL be rolled back so that `publishedAt` is not set

### Requirement: Publication and Notification Flow

On transition to `published`, the system SHALL: (1) set `publishedAt` to the current timestamp; (2) generate the decision document per REQ-BD-9; (3) emit notifications to the bezwaarmaker, the gemachtigde (if registered on the case), the primair beslisser, and the advisory committee secretaris (if `advisoryReport` is set); (4) record the notified recipients in `notifiedRecipients`; (5) transition the bezwaar case status to "Beslissing op bezwaar"; (6) start the beroep-clock from `effectiveDate`. When a bezwaarmaker is a natural person and a MijnOverheid Berichtenbox integration is configured, the decision SHALL additionally be filed in the Berichtenbox.

**Feature tier**: V1
**AWB reference**: Art. 7:12, 3:41, 3:43, 6:7, 6:8

#### Scenario: Standard publication notifies all relevant parties

- **GIVEN** a bezwaarBesluit with bezwaarmaker `B. de Vries`, gemachtigde `mr. K. Jansen`, primair beslisser `Ambtenaar W. Smit`, and an `advisoryReport` referencing committee secretaris `S. El Idrissi`
- **WHEN** the beslisser publishes the bezwaarBesluit
- **THEN** `publishedAt` SHALL be set to the current timestamp
- **AND** notifications SHALL be emitted to all four parties
- **AND** `notifiedRecipients` SHALL list the UIDs/email addresses of those four parties
- **AND** the bezwaar case status SHALL transition to "Beslissing op bezwaar"

#### Scenario: MijnOverheid Berichtenbox delivery when configured

- **GIVEN** a bezwaarmaker who is a natural person with a BSN registered on the case
- **AND** a MijnOverheid Berichtenbox integration is configured for the bestuursorgaan
- **WHEN** the bezwaarBesluit is published
- **THEN** the decision document SHALL be filed in the Berichtenbox
- **AND** the Berichtenbox delivery confirmation SHALL be appended to `notifiedRecipients` with a `berichtenbox:` prefix

#### Scenario: Beroep-clock starts on effectiveDate

- **GIVEN** a published bezwaarBesluit with `effectiveDate = 2026-05-15`
- **WHEN** the bezwaarmaker checks the case detail view on 2026-05-20
- **THEN** the system SHALL display a "Beroepstermijn loopt" indicator
- **AND** the indicator SHALL show the calculated end-of-term date 2026-06-26 (6 weeks from `effectiveDate`)
- **AND** after 2026-06-26 the indicator SHALL change to "Beroepstermijn verstreken"
