# vth-lhso-classification Specification

## Purpose
TBD - created by archiving change vth-workflow-configuration-07-lhso-classification. Update Purpose after archive.
## Requirements
### Requirement: LHSO lookup service and endpoints

The system SHALL provide an LHSO lookup service and endpoints that return the full 16-cell matrix and a single suggestion for a validated gedrag×gevolgen pair.

**Spec ref**: REQ-VTH-003-B

#### Scenario: Lookup a matrix cell

- **WHEN** a lookup is requested for gedrag=C and gevolgen=3
- **THEN** the service SHALL return the matching cell with its suggested intervention and description

#### Scenario: Reject invalid inputs

- **WHEN** a lookup uses an out-of-range gedrag (E) or gevolgen (5)
- **THEN** the service SHALL return a validation error

### Requirement: LHSO classification panel with override enforcement

The system SHALL provide a classification panel in the Handhavingszaak detail that shows the suggested intervention and requires an override reason when the chosen intervention differs.

**Spec ref**: REQ-VTH-003-B, REQ-VTH-003-C

#### Scenario: Override requires a reason

- **WHEN** a handler selects an intervention that differs from the suggested intervention
- **THEN** an override-reason field SHALL become visible and required before the classification can be saved

