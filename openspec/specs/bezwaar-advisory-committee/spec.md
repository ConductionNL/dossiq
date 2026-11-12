---
status: done
---

## Purpose

Records advisory reports from the bezwaarschriftencommissie (objection advisory committee, AWB art. 7:13) on bezwaar cases, and tracks committee composition to safeguard independence and surface conflicts of interest.

@e2e exclude Advisory committee report schema is V1; generic index page, no specific Playwright-testable UI interactions yet.

## Requirements

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

### Requirement: a panel whose independence cannot be verified does not start deliberating

When the advice request moves from `assigned` to `in-deliberation`, the independence check MUST be able to run. When it cannot, the transition MUST be refused. That covers a store read that fails while the check resolves the contested primair besluit, and an objection or decision schema that is not configured. The refusal MUST name no conflicting member. Its reason MUST be "Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)". An `independence-check-unverifiable` entry MUST be written to the request's trail before the refusal, and the refusal MUST stand whether or not that entry is written.

#### Scenario: the store fails while the check reads the contested decision
- GIVEN an advice request in `assigned` with panel `["a.bakker", "p.janssen"]`
- AND reading the contested primair besluit fails
- WHEN the bezwaar handler moves the request to `in-deliberation`
- THEN the transition is refused with reason "Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)"
- AND the request stays in `assigned`
- AND the request's trail holds an `independence-check-unverifiable` entry with no conflicting member

#### Scenario: the decision schema is not configured
- GIVEN no `decision_schema` is configured
- WHEN the bezwaar handler moves an advice request with a panel to `in-deliberation`
- THEN the transition is refused the same way
- AND the request stays in `assigned`

### Requirement: the panel check and the advice transition fail closed on every path

The panel check MUST answer unverifiable, never independent, when no object store is available. The advice transition MUST mark its save under the stored advice request's own id, read after the request is loaded, so the guard recognises the transition whatever id form the caller used.

#### Scenario: no object store
@e2e exclude needs an instance without OpenRegister; covered by PanelIndependenceCheckerGoldenTest
- **GIVEN** no object store is available
- **WHEN** the panel check runs for a panel
- **THEN** the verdict is unverifiable, with no conflicting member

#### Scenario: the committee issues the advice through another id form
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation` that the store also finds by its slug
- **WHEN** `transitionAdviceStatus()` is called with the slug and moves it to `advice-issued`
- **THEN** the guard lets the save through
- **AND** the request is stored in `advice-issued`
