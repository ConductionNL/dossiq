# Spec delta: vth-testing

## ADDED Requirements

### Requirement: VTH service unit tests

The system SHALL provide unit tests covering the main methods and edge cases of the VTH services.

**Spec ref**: REQ-VTH-004, REQ-VTH-005, REQ-VTH-003, REQ-VTH-006, REQ-VTH-002

#### Scenario: Unit suite passes

- **WHEN** the VTH unit test suite runs
- **THEN** leges calculation/verrekening/refund/versioning, beschikking merge/validation/versioning, LHSO lookups, DSO mapping, and mobile photo/GPS/validation assertions SHALL all pass

### Requirement: VTH workflow integration tests

The system SHALL provide integration tests for the three VTH workflow transition paths including guard validation and notifications.

**Spec ref**: REQ-VTH-001, REQ-VTH-002, REQ-VTH-003

#### Scenario: Workflow transitions pass

- **WHEN** the integration suite runs
- **THEN** the Omgevingsvergunning intake→beschikking→verleend path, the Toezichtzaak inspection flow, and the Handhavingszaak LHSO/intervention flow SHALL pass with guards enforced and notifications sent

### Requirement: DSO end-to-end test

The system SHALL provide an end-to-end test of the DSO verzoek → case creation → status-pushback flow.

**Spec ref**: REQ-VTH-006

#### Scenario: DSO round-trip passes

- **WHEN** the E2E test simulates a STAM 2.0 verzoek and transitions the resulting case
- **THEN** the case SHALL be created with the correct zaaktype and a VergunningStatusChangedEvent SHALL be dispatched for each transition with the correct payload
