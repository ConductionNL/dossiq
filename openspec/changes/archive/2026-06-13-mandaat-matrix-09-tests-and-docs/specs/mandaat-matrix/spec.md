# mandaat-matrix Specification — Member 09: Tests, @spec Tags, and Documentation

---
status: proposed
---

## Purpose

Verify the completed mandate-matrix feature end-to-end, annotate every public method with `@spec`
traceability, and document admin operations.

## ADDED Requirements

### Requirement: Authorization and Escalation Test Coverage

The system SHALL include unit and integration tests covering the authorization verdict and the
escalation workflow.

#### Scenario: Unit tests cover the verdict matrix

- GIVEN the MandaatCheckService test suite
- WHEN it runs
- THEN it SHALL assert authorized, niet_bevoegd, plafond_overschreden,
  subdelegatie_niet_toegestaan, waarnemer, and temporal-version paths, and SHALL pass

#### Scenario: Integration tests cover the escalation workflow

- GIVEN the escalation workflow integration test
- WHEN it runs
- THEN it SHALL assert escalation creation on plafond overshoot, approval executing the decision
  with MandaatGebruik logged, rejection leaving the case unchanged, and personnel-change rerouting,
  and SHALL pass

#### Scenario: Authorization guard verified on case decisions

- GIVEN the case-decision authorization integration test
- WHEN it runs
- THEN it SHALL assert that an authorized user's decision succeeds and is logged, an unauthorized
  user's decision is blocked with an escalation, and a waarnemer is authorized with a waarnemer
  flag, and SHALL pass

### Requirement: Spec Traceability and Admin Documentation

Every new public service method SHALL carry an `@spec` tag, and admin documentation SHALL describe
the mandate-matrix operations.

#### Scenario: @spec tags present on new services

- GIVEN the new mandate-matrix service classes
- WHEN they are inspected
- THEN each SHALL have a file-level `@spec` docblock and each public method SHALL link to the
  relevant requirement via an `@spec` tag

#### Scenario: Admin guide documents import and role management

- GIVEN the admin documentation
- WHEN it is reviewed
- THEN it SHALL describe the decidesk import workflow, role-hierarchy setup, waarnemer assignment,
  and troubleshooting for missing roles and validation errors
