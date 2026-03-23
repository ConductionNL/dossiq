## ADDED Requirements

### Requirement: Advisory Committee Report Schema

The system SHALL support recording advisory reports from the bezwaarschriftencommissie (objection advisory committee) as OpenRegister objects. Under AWB art. 7:13, many municipalities use an independent advisory committee to advise on bezwaar cases.

**Feature tier**: V1
**Schema.org mapping**: `schema:Report` with `schema:about` referencing the bezwaar case

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `case` | reference (UUID) | Yes | The bezwaar case this report belongs to |
| `hearingSession` | reference (UUID) | No | The hearing session this report is based on |
| `committeeChair` | reference (UUID to role) | Yes | Voorzitter who signed the report |
| `committeeMembers` | array of references | No | Committee members involved |
| `adviceDate` | date | Yes | Date the advice was issued |
| `adviceType` | enum | Yes | gegrond, ongegrond, deels_gegrond, niet_ontvankelijk |
| `summary` | string (text) | Yes | Summary of the committee's advice |
| `grounds` | string (text) | Yes | Legal reasoning and grounds for the advice |
| `recommendation` | string (text) | Yes | Recommended action for the bestuursorgaan |
| `deviationFromPrimaryDecision` | boolean | Yes | Whether the committee advises differently from the original decision |
| `reportDocument` | reference (UUID) | No | Full advisory report document |

#### Scenario: Create advisory committee report

- **WHEN** the bezwaarschriftencommissie has completed its review of bezwaar BZ-2026-0042
- **THEN** the commissie secretaris SHALL create an advisory report with `adviceType` and `recommendation`
- **AND** the case status SHALL transition to "Advies uitgebracht"
- **AND** the report SHALL reference the hearing session if one was held

#### Scenario: Advisory report recommends overturning original decision

- **WHEN** the committee issues advice with `adviceType: gegrond` and `deviationFromPrimaryDecision: true`
- **THEN** the system SHALL flag the case as requiring attention from the decision maker
- **AND** a notification SHALL be sent to the user with the "Beslisser" role on the case

#### Scenario: Advisory report with partial upholding

- **WHEN** the committee issues advice with `adviceType: deels_gegrond`
- **THEN** the `recommendation` field SHALL specify which grounds are upheld and which are rejected
- **AND** the `grounds` field SHALL contain the legal reasoning for each determination

### Requirement: Committee Composition Tracking

The system SHALL track which committee members participated in each bezwaar case to ensure independence and prevent conflicts of interest.

**Feature tier**: V1

#### Scenario: Committee member was involved in original decision

- **WHEN** a user with role "Lid commissie" on the bezwaar case is also the "Primair beslisser" on the contested original case
- **THEN** the system SHALL display a warning: "Commissielid was betrokken bij het primaire besluit"
- **AND** the warning SHALL be visible to the voorzitter commissie
- **AND** the system SHALL NOT block the assignment (it is an advisory warning, not an enforcement)

#### Scenario: Minimum committee composition

- **WHEN** an advisory report is being created
- **THEN** the system SHALL require at least a `committeeChair` to be assigned
- **AND** the system SHALL display a recommendation to have at least 3 committee members (voorzitter + 2 leden) per municipal best practice
