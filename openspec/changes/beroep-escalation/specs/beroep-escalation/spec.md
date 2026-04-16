---
status: in-progress
---
# beroep-escalation Specification

## Purpose

Enable treatment of beroep (appeal at the administrative court) as a first-class case type in Procest. This spec covers: pre-seeding the Beroep case type and its court-proceedings status lifecycle, the escalation action from a completed bezwaar case to a linked beroep case, data inheritance from bezwaar, the voorlopige voorziening urgency flag, court document management (verweerschrift and uitspraak), and informational display of hoger beroep possibilities after a ruling is recorded.

## Context

Beroep is the third tier of the Dutch administrative appeal process:
1. **Bezwaar** (Awb art. 7) — objection to the original decision, handled by the municipality
2. **Beroep** (Awb art. 8:1) — appeal to the bestuursrechter (administrative court) after a beslissing op bezwaar
3. **Hoger beroep** — further appeal to the Afdeling bestuursrechtspraak van de Raad van State (ABRvS) or the Centrale Raad van Beroep (CRvB)

Procest already models bezwaar via the `objection`, `hearingSession`, `advisoryReport`, and `appealDecision` entities (ADR-000, Group 6). This change adds seed configuration so that municipalities can track beroep proceedings within the same case management system, maintaining the audit trail from the original besluit through bezwaar to beroep.

**Legal references**: Awb art. 8:1 (beroepsbevoegdheid), art. 8:7 (rechtsmacht), art. 8:37 (termijnen verweerschrift), art. 8:66 (uitspraakrechtbank), art. 8:1 jo. 8:104 (hoger beroep).

## Requirements

### REQ-BER-001: Beroep Case Type Pre-Seeded Configuration

The system SHALL provide a pre-seeded "Beroep" case type for tracking appeals to the administrative court (bestuursrechter), available immediately after installation without manual configuration.

**ZGW mapping**: `zaaktype` with `omschrijving` "Beroep"
**AWB reference**: Art. 8:1 (beroep bij de rechtbank)

| Property | Value |
|----------|-------|
| `title` | Beroep |
| `description` | Beroepsprocedure bij de bestuursrechter conform Awb hoofdstuk 8 |
| `processingDeadline` | P26W (6 months indicative; actual timeline determined by the court) |
| `extensionAllowed` | false |
| `suspensionAllowed` | true |
| `origin` | external |
| `trigger` | Beroepschrift bij de bestuursrechter |
| `subject` | Beroep tegen beslissing op bezwaar |

#### Scenario REQ-BER-001.1: Beroep case type is available after installation

- **GIVEN** Procest is installed on a Nextcloud instance
- **WHEN** the repair step runs
- **THEN** a `caseType` object with `title` "Beroep" SHALL exist in the procest register
- **AND** the case type SHALL have `isDraft: false` (immediately usable)
- **AND** the case type SHALL have `processingDeadline: "P26W"` and `suspensionAllowed: true`

#### Scenario REQ-BER-001.2: Beroep case type is idempotent on re-import

- **GIVEN** the repair step has already run once
- **WHEN** the repair step runs again
- **THEN** no duplicate "Beroep" case type SHALL be created
- **AND** the existing case type SHALL remain unmodified

---

### REQ-BER-002: Beroep Status Types

The system SHALL provide 9 status types for the Beroep case type reflecting the stages of court proceedings that the municipality needs to track.

| Order | Status Type | Description | isFinal |
|-------|-------------|-------------|---------|
| 1 | Beroep ontvangen | Beroepschrift ontvangen van rechtbank | false |
| 2 | Verweerschrift in voorbereiding | Gemeente bereidt verweerschrift voor | false |
| 3 | Verweerschrift ingediend | Verweerschrift ingediend bij de rechtbank | false |
| 4 | Zitting gepland | Zitting bij de rechtbank is ingepland | false |
| 5 | Zitting afgerond | Rechtbankzitting heeft plaatsgevonden | false |
| 6 | Uitspraak ontvangen | Uitspraak van de rechtbank is ontvangen | false |
| 7 | Afgehandeld | Zaak afgehandeld na uitspraak | true |
| 90 | Ingetrokken | Beroep ingetrokken door appellant | true |
| 91 | Schikking | Zaak buiten rechte geschikt | true |

#### Scenario REQ-BER-002.1: All status types are seeded

- **GIVEN** the repair step has run
- **THEN** exactly 9 `statusType` records SHALL exist for the Beroep case type
- **AND** they SHALL be ordered by the `order` field (1 through 7, with 90 and 91 for terminal off-path statuses)
- **AND** statuses at orders 7, 90, and 91 SHALL have `isFinal: true`

#### Scenario REQ-BER-002.2: Status flow reflects court proceedings timeline

- **GIVEN** a beroep case created at "Beroep ontvangen"
- **WHEN** the behandelaar advances the case through the lifecycle
- **THEN** the status order SHALL follow: ontvangen → verweerschrift in voorbereiding → verweerschrift ingediend → zitting gepland → zitting afgerond → uitspraak ontvangen → afgehandeld
- **AND** early termination statuses (Ingetrokken, Schikking) SHALL be selectable at any point regardless of order

---

### REQ-BER-003: Escalation from Bezwaar to Beroep

The system SHALL support creating a beroep case from a completed bezwaar case, linking the two cases as parent-child. This action SHALL only be available when the bezwaar case is in a state indicating the beslissing op bezwaar has been issued.

#### Scenario REQ-BER-003.1: Escalation action is visible at the correct bezwaar status

- **GIVEN** a bezwaar case BZ-2026-0042 with status "Beslissing op bezwaar" or "Afgehandeld"
- **WHEN** the behandelaar views the bezwaar case detail
- **THEN** an "Escaleren naar beroep" action SHALL be visible in the case actions
- **AND** the action SHALL NOT be visible when the bezwaar case is in any earlier status

#### Scenario REQ-BER-003.2: Create beroep case from bezwaar

- **GIVEN** a beroepschrift is received for bezwaar case BZ-2026-0042
- **WHEN** the behandelaar clicks "Escaleren naar beroep"
- **THEN** the system SHALL open a pre-filled form (BeroepEscalatieDialog)
- **AND** on save, a new beroep `case` SHALL be created with `caseType` "Beroep"
- **AND** the beroep case SHALL have `parentCase` set to the UUID of BZ-2026-0042
- **AND** the initial status SHALL be "Beroep ontvangen"
- **AND** the beroep case identifier SHALL be auto-generated in the format BR-{YYYY}-{NNNN}

#### Scenario REQ-BER-003.3: Bezwaar case displays link to beroep case

- **GIVEN** a beroep case BR-2026-0015 has been created from bezwaar BZ-2026-0042
- **WHEN** the behandelaar views bezwaar case BZ-2026-0042
- **THEN** the case detail SHALL display a link to "Beroep BR-2026-0015" in the activity timeline or related cases section
- **AND** the link SHALL navigate directly to the beroep case detail

---

### REQ-BER-004: Beroep Case Inherits Relevant Data from Bezwaar

The system SHALL pre-fill beroep case fields from the source bezwaar case when creating via escalation. The behandelaar SHALL be able to modify all pre-filled data before saving.

#### Scenario REQ-BER-004.1: Pre-fill from bezwaar data

- **GIVEN** bezwaar case BZ-2026-0042 has:
  - A bezwaarmaker (initiator role: M. Kowalski)
  - A `appealDecision` recording the beslissing op bezwaar
  - Objection grounds in the linked `objection` record
- **WHEN** the BeroepEscalatieDialog opens
- **THEN** the form SHALL pre-fill:
  - Title derived from the bezwaar case title (e.g., "Beroep: [bezwaar title]")
  - Description referencing the original bezwaar grounds
  - Bezwaarmaker M. Kowalski as appellant on the beroep case (role: Appellant)
- **AND** the behandelaar SHALL be able to modify all pre-filled values before saving

#### Scenario REQ-BER-004.2: Contested decision is linked

- **GIVEN** bezwaar BZ-2026-0042 has an `appealDecision` record (beslissing op bezwaar dated 2026-01-15)
- **WHEN** the beroep case BR-2026-0015 is created from this bezwaar
- **THEN** the beroep case description SHALL reference the beslissing op bezwaar (date and identifier)
- **AND** the behandelaar SHALL see the contested decision details on the beroep case detail view

---

### REQ-BER-005: Voorlopige Voorziening Tracking

The system SHALL allow marking a beroep case as having an associated verzoek om voorlopige voorziening (request for interim relief), and SHALL display a visual urgency indicator when this flag is set.

#### Scenario REQ-BER-005.1: Set voorlopige voorziening flag during escalation

- **GIVEN** the BeroepEscalatieDialog is open
- **WHEN** the behandelaar toggles "Appellant heeft ook voorlopige voorziening aangevraagd"
- **THEN** the `voorzieningRequested` caseProperty SHALL be set to `true` on the new beroep case
- **AND** the dialog SHALL display an informational note: "Een voorlopige voorziening betekent spoedeisend karakter — behandel met prioriteit"

#### Scenario REQ-BER-005.2: Urgency indicator on beroep case detail

- **GIVEN** beroep case BR-2026-0003 has `voorzieningRequested = true`
- **WHEN** the behandelaar views the case detail
- **THEN** a prominently displayed badge SHALL appear: "Voorlopige voorziening aangevraagd"
- **AND** the case priority SHALL be set to "urgent" automatically when `voorzieningRequested = true`

---

### REQ-BER-006: Court Proceedings Document Management

The system SHALL support uploading key court documents and recording the court ruling within the beroep case.

#### Scenario REQ-BER-006.1: Upload verweerschrift and trigger status transition

- **GIVEN** the municipality is preparing its defense for beroep case BR-2026-0015
- **AND** the case is in status "Verweerschrift in voorbereiding"
- **WHEN** the behandelaar uploads a PDF document and selects document type "Verweerschrift"
- **THEN** the document SHALL be stored as a `caseDocument` linked to BR-2026-0015
- **AND** the behandelaar SHALL be prompted: "Verweerschrift indienen? Dit zet de status op 'Verweerschrift ingediend'."
- **AND** on confirmation, the case status SHALL transition to "Verweerschrift ingediend"

#### Scenario REQ-BER-006.2: Record court ruling outcome

- **GIVEN** the court issues its ruling on beroep case BR-2026-0015
- **AND** the case is in status "Zitting afgerond"
- **WHEN** the behandelaar opens the UitspraakDialog and selects ruling outcome
- **THEN** the available outcomes SHALL be:
  - beroep_gegrond ("Beroep gegrond")
  - beroep_ongegrond ("Beroep ongegrond")
  - deels_gegrond ("Beroep deels gegrond")
  - niet_ontvankelijk ("Niet-ontvankelijk")
- **AND** on save, the case `result` SHALL be set to the selected outcome's `resultType`
- **AND** the case status SHALL transition to "Uitspraak ontvangen"

#### Scenario REQ-BER-006.3: Follow-up task for gegrond ruling

- **GIVEN** the ruling outcome is "beroep_gegrond" or "deels_gegrond"
- **WHEN** the behandelaar saves the uitspraak
- **THEN** the UitspraakDialog SHALL offer an option: "Maak taak aan voor nieuw besluit"
- **AND** on confirmation, a new `task` SHALL be created on the beroep case: "Nieuw besluit nemen naar aanleiding van uitspraak rechtbank"
- **AND** the task SHALL have status "available" and priority "high"

---

### REQ-BER-007: Hoger Beroep Awareness

The system SHALL inform users about the possibility of hoger beroep after a court ruling but SHALL NOT implement a full hoger beroep workflow.

#### Scenario REQ-BER-007.1: Display hoger beroep information after ruling

- **GIVEN** a court ruling has been recorded on beroep case BR-2026-0015
- **AND** the case status is "Uitspraak ontvangen" or "Afgehandeld"
- **WHEN** the behandelaar views the beroep case detail
- **THEN** the system SHALL display the following informational text in the HogerBeroepBanner component:
  > "Na de uitspraak van de rechtbank kan hoger beroep worden ingesteld bij de Afdeling bestuursrechtspraak van de Raad van State (ABRvS) of de Centrale Raad van Beroep (CRvB). De termijn voor hoger beroep bedraagt 6 weken na de uitspraak."
- **AND** the banner SHALL be dismissable (hidden for the session) but SHALL NOT create a hoger beroep case automatically

#### Scenario REQ-BER-007.2: No automated hoger beroep case creation

- **GIVEN** a court ruling is recorded on beroep case BR-2026-0015
- **THEN** the system SHALL NOT automatically create a new case for hoger beroep
- **AND** the system SHALL NOT display any button labeled "Escaleren naar hoger beroep" (this is a non-goal for this change)
