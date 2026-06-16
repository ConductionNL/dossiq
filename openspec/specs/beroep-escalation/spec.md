## Purpose

Provides a pre-seeded "Beroep" (appeal) case type and the workflow to escalate a completed bezwaar case to the administrative court (bestuursrechter, Awb hoofdstuk 8), including court-proceedings status types, document tracking, and hoger-beroep awareness.

@e2e exclude Beroep case type is V1 seed data imported via repair step; covered by PHPUnit, not Playwright.

## Requirements

### Requirement: Beroep Case Type Pre-Seeded Configuration

The system SHALL provide a pre-seeded "Beroep" (appeal) case type for tracking appeals to the administrative court (bestuursrechter). Beroep is the next step after bezwaar when a citizen disagrees with the beslissing op bezwaar.

**Feature tier**: V1
**ZGW mapping**: `zaaktype` with `omschrijving` "Beroep"
**AWB reference**: Art. 8:1 (beroep bij de rechtbank)

| Property | Value |
|----------|-------|
| `title` | Beroep |
| `description` | Beroepsprocedure bij de bestuursrechter conform Awb hoofdstuk 8 |
| `processingDeadline` | P26W (6 months indicative, actual timeline determined by court) |
| `extensionAllowed` | false |
| `suspensionAllowed` | true |
| `origin` | external |
| `trigger` | Beroepschrift bij de bestuursrechter |
| `subject` | Beroep tegen beslissing op bezwaar |

#### Scenario: Beroep case type is available after installation

- **WHEN** the Procest app repair step runs
- **THEN** a case type "Beroep" SHALL exist in the procest register
- **AND** the case type SHALL have appropriate status types for court proceedings tracking

### Requirement: Beroep Status Types

The system SHALL provide status types for the Beroep case type reflecting the stages of court proceedings that the municipality needs to track.

**Feature tier**: V1

| Order | Status Type | Description |
|-------|-------------|-------------|
| 1 | Beroep ontvangen | Beroepschrift ontvangen van rechtbank |
| 2 | Verweerschrift in voorbereiding | Municipality preparing defense |
| 3 | Verweerschrift ingediend | Defense submitted to court |
| 4 | Zitting gepland | Court hearing scheduled |
| 5 | Zitting afgerond | Court hearing completed |
| 6 | Uitspraak ontvangen | Court ruling received |
| 7 | Afgehandeld | Case closed after ruling |
| -- | Ingetrokken | Appeal withdrawn by appellant |
| -- | Schikking | Settled out of court |

#### Scenario: Beroep status types are seeded

- **WHEN** the repair step completes
- **THEN** 9 status types SHALL exist for the Beroep case type
- **AND** they SHALL be ordered to reflect the court proceedings timeline

### Requirement: Escalation from Bezwaar to Beroep

The system SHALL support creating a beroep case from a completed bezwaar case, linking the two cases as parent-child. This happens when the bezwaarmaker appeals the beslissing op bezwaar at the administrative court.

**Feature tier**: V1

#### Scenario: Create beroep case from bezwaar

- **WHEN** a beroepschrift is received for bezwaar case BZ-2026-0042
- **AND** the bezwaar case has status "Beslissing op bezwaar" or "Afgehandeld"
- **THEN** the behandelaar SHALL be able to create a beroep case linked to the bezwaar case
- **AND** the beroep case SHALL reference the bezwaar case as its parent
- **AND** the beroep case SHALL reference the beslissing op bezwaar as the contested decision
- **AND** the bezwaar case SHALL display a link to the beroep case in its timeline

#### Scenario: Beroep case inherits relevant data from bezwaar

- **WHEN** a beroep case is created from bezwaar BZ-2026-0042
- **THEN** the system SHALL pre-fill:
  - Bezwaarmaker becomes the appellant (initiator) on the beroep case
  - The contested decision references the beslissing op bezwaar
  - The case description references the original bezwaar grounds
- **AND** the behandelaar SHALL be able to modify pre-filled data before saving

#### Scenario: Voorlopige voorziening tracking

- **WHEN** the appellant has also requested a voorlopige voorziening (interim relief) alongside the beroep
- **THEN** the beroep case SHALL have a boolean `voorzieningRequested` property set to `true`
- **AND** the system SHALL display a flag on the case indicating urgency due to the interim relief request

### Requirement: Court Proceedings Document Management

The system SHALL support tracking key court documents within the beroep case.

**Feature tier**: V1

#### Scenario: Upload verweerschrift (defense document)

- **WHEN** the municipality prepares its defense for beroep case BR-2026-0015
- **THEN** the behandelaar SHALL be able to upload the verweerschrift as a case document
- **AND** the case status SHALL transition to "Verweerschrift ingediend"

#### Scenario: Record court ruling

- **WHEN** the court issues its ruling (uitspraak) on beroep case BR-2026-0015
- **THEN** the behandelaar SHALL record the ruling outcome: beroep_gegrond, beroep_ongegrond, deels_gegrond, niet_ontvankelijk
- **AND** the case status SHALL transition to "Uitspraak ontvangen"
- **AND** if the ruling requires the municipality to take a new decision, the system SHALL allow creating a follow-up task

### Requirement: Hoger Beroep Awareness

The system SHALL inform users about the possibility of hoger beroep (further appeal) after a court ruling, but SHALL NOT implement a full hoger beroep workflow.

**Feature tier**: V1

#### Scenario: Display hoger beroep information after ruling

- **WHEN** a court ruling is recorded on a beroep case
- **THEN** the system SHALL display informational text: "Na de uitspraak van de rechtbank kan hoger beroep worden ingesteld bij de Afdeling bestuursrechtspraak van de Raad van State (ABRvS) of de Centrale Raad van Beroep (CRvB)"
- **AND** the system SHALL NOT create an automated hoger beroep case (this is a non-goal for this change)
