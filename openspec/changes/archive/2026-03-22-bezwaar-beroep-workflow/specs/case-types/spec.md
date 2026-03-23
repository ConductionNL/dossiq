## MODIFIED Requirements

### Requirement: Case Type Pre-Seeded Data

The system SHALL provide pre-seeded case types that are imported via the repair step. In addition to any existing pre-seeded case types, the system SHALL now include Bezwaar and Beroep case types with their associated status types, role types, and workflow templates.

**Feature tier**: V1

The repair step SHALL import the following new case types alongside existing ones:

| Case Type | Processing Deadline | Extension | Suspension | Origin |
|-----------|-------------------|-----------|------------|--------|
| Bezwaar | P6W | P6W | Yes | external |
| Beroep | P26W | No | Yes | external |

Each case type SHALL include its associated:
- Status types (see bezwaar-lifecycle and beroep-escalation specs)
- Role types (see bezwaar-lifecycle spec)
- Workflow template (see workflow-definition-model spec)

#### Scenario: Bezwaar and Beroep case types are available after installation

- **WHEN** the Procest app repair step runs for the first time or after an update
- **THEN** case types "Bezwaar" and "Beroep" SHALL exist in the procest register
- **AND** each SHALL have its complete set of status types, role types, and an active workflow template
- **AND** existing case types SHALL NOT be affected by the addition

#### Scenario: Pre-seeded case types are not duplicated on re-run

- **WHEN** the repair step runs again on an installation that already has Bezwaar and Beroep case types
- **THEN** the system SHALL NOT create duplicate case types
- **AND** existing customizations to the case types SHALL be preserved
