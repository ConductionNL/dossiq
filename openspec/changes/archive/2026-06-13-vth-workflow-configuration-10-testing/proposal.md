---
kind: code
depends_on:
  - vth-workflow-configuration-09-admin-settings
chain:
  - vth-workflow-configuration-01-config-foundation
  - vth-workflow-configuration-02-workflow-templates
  - vth-workflow-configuration-03-leges-engine
  - vth-workflow-configuration-04-leges-config-ui
  - vth-workflow-configuration-05-beschikking-generation
  - vth-workflow-configuration-06-mobile-inspection
  - vth-workflow-configuration-07-lhso-classification
  - vth-workflow-configuration-08-dso-integration
  - vth-workflow-configuration-09-admin-settings
  - vth-workflow-configuration-10-testing
  - vth-workflow-configuration-11-quality-docs
---

# VTH Workflow Configuration — 10 Testing

> Member 10 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 09 (last functional member). Adds unit, integration, and end-to-end tests across the VTH feature set.

## Summary

Add the cross-cutting test suite for the VTH feature: unit tests for the VTH services (leges, beschikking, LHSO, DSO intake, mobile inspection), integration tests for workflow transitions, and an end-to-end DSO integration test. Traces to giant Tasks 21, 22, 23. (Member 01's integration test covers config materialisation; this member covers service/behaviour.)

## Scope

### In Scope

- Unit tests for the five VTH services.
- Integration tests for the three workflow transition paths.
- End-to-end DSO verzoek → case → status-pushback test.

### Out of Scope

- Per-member smoke tests already authored in members 02–09.
- The config-materialisation integration test (member 01).

## Dependencies

- **vth-workflow-configuration-09-admin-settings**: all functional members exist before the cross-cutting suite runs.

## Acceptance Criteria

1. GIVEN the VTH services, WHEN the unit suite runs, THEN main methods and edge cases pass.
2. GIVEN the workflows, WHEN the integration suite runs, THEN full transition paths and guards pass with notifications.
3. GIVEN the DSO flow, WHEN the E2E test runs, THEN verzoek → case → status pushback completes.
