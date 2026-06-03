## Purpose

@e2e exclude Beslissing op bezwaar schema is V1; generic index page, no specific Playwright-testable UI interactions yet.

## ADDED Requirements

### Requirement: Decision on Objection Schema

The system SHALL support recording the beslissing op bezwaar (decision on objection) as a formal decision object linked to the bezwaar case. This extends the existing `decision` schema with bezwaar-specific properties.

**Feature tier**: V1
**ZGW mapping**: `besluit` with `besluittype` "Beslissing op bezwaar"
**AWB reference**: Art. 7:11 (heroverweging), Art. 7:12 (motivering)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `case` | reference (UUID) | Yes | The bezwaar case |
| `contestedDecision` | reference (UUID) | Yes | The original besluit being contested |
| `advisoryReport` | reference (UUID) | No | The committee's advisory report |
| `dispositionType` | enum | Yes | gegrond, ongegrond, deels_gegrond, niet_ontvankelijk |
| `dispositionDetails` | string (text) | Yes | Detailed motivation for the decision (motiveringsplicht art. 7:12) |
| `followsAdvice` | boolean | No | Whether the decision follows the committee's advice |
| `deviationReason` | string (text) | No | Reason for deviating from committee advice (required when followsAdvice is false) |
| `remedialAction` | string (text) | No | What corrective action is taken if gegrond/deels_gegrond |
| `replacementDecision` | reference (UUID) | No | New besluit that replaces the contested one |
| `decisionDate` | date | Yes | Date the decision was made |
| `effectiveDate` | date | Yes | Date the decision takes legal effect |
| `appealInformation` | string (text) | Yes | Information about beroep possibilities (rechtsmiddelenclausule) |
| `decisionMaker` | reference (UUID to role) | Yes | The person/body that made the decision |
| `decisionDocument` | reference (UUID) | No | The formal decision letter document |

#### Scenario: Record gegrond (upheld) decision on bezwaar

- **WHEN** the beslisser records a decision with `dispositionType: gegrond` on bezwaar BZ-2026-0042
- **THEN** the decision object SHALL be created with required `dispositionDetails` explaining the reconsideration
- **AND** the case status SHALL transition to "Beslissing op bezwaar"
- **AND** `remedialAction` SHALL describe what corrective action is taken
- **AND** `appealInformation` SHALL be populated with information about beroep at the administrative court

#### Scenario: Decision deviates from advisory committee advice

- **WHEN** the beslisser records a decision that does not follow the committee's advice
- **AND** `followsAdvice` is set to `false`
- **THEN** the system SHALL require `deviationReason` to be filled in
- **AND** the deviation SHALL be recorded in the case audit trail
- **AND** per art. 7:13 lid 7, the deviationReason SHALL be included in the decision letter

#### Scenario: Decision includes rechtsmiddelenclausule

- **WHEN** a beslissing op bezwaar is recorded
- **THEN** `appealInformation` SHALL include:
  - The option to file beroep at the rechtbank (administrative court)
  - The 6-week term for filing beroep (art. 6:7)
  - The name of the competent court
- **AND** if `appealInformation` is left empty, the system SHALL display a warning: "Rechtsmiddelenclausule ontbreekt"

#### Scenario: Niet-ontvankelijk declaration

- **WHEN** the beslisser records a decision with `dispositionType: niet_ontvankelijk`
- **THEN** the case status SHALL transition to "Niet-ontvankelijk"
- **AND** `dispositionDetails` SHALL explain why the bezwaar is inadmissible (e.g., termijn overschreden, geen belanghebbende, geen besluit)

### Requirement: Decision Notification

The system SHALL notify the bezwaarmaker when a decision on their objection has been made.

**Feature tier**: V1

#### Scenario: Notify bezwaarmaker of decision

- **WHEN** a beslissing op bezwaar is recorded and the case transitions to "Beslissing op bezwaar"
- **THEN** the system SHALL trigger an automatic action to notify the bezwaarmaker
- **AND** the notification SHALL reference the case number and decision type
- **AND** if a gemachtigde (representative) is registered on the case, the gemachtigde SHALL also be notified

### Requirement: Heroverweging (Full Reconsideration)

The beslissing op bezwaar SHALL be based on a complete reconsideration (heroverweging) of the original decision, not just a review of the bezwaarmaker's arguments. This is mandated by AWB art. 7:11.

**Feature tier**: V1

#### Scenario: Reconsideration scope includes new facts

- **WHEN** the behandelaar prepares the beslissing op bezwaar
- **THEN** the decision form SHALL include a field for ex nunc (current situation) assessment
- **AND** the system SHALL display guidance: "De heroverweging betreft een volledige heroverweging, inclusief feiten en omstandigheden ten tijde van de beslissing op bezwaar"

#### Scenario: Reformatio in peius warning

- **WHEN** the reconsideration could lead to a worse outcome for the bezwaarmaker than the original decision
- **THEN** the system SHALL display a warning: "Let op: reformatio in peius -- het bezwaar mag in beginsel niet leiden tot een voor de bezwaarmaker nadeliger besluit"
