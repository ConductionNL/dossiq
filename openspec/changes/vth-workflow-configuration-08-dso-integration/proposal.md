---
kind: code
depends_on:
  - vth-workflow-configuration-02-workflow-templates
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

# VTH Workflow Configuration — 08 DSO Integration

> Member 8 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 02 (the Omgevingsvergunning workflow must exist for cases to be created and to transition). Implements DSO intake, status pushback, and deadline tracking.

## Summary

Implement the DSO (Digitaal Stelsel Omgevingswet) integration: intake of verzoeken into auto-created cases (`DsoIntakeService`, `DsoCaseService`, `VergunningaanvraagCreatedListener`), status pushback to DSO-LV (`VergunningStatusChangedEvent`, `StatusChangeDispatcherListener`), and deadline tracking with warnings (`DsoDeadlineService`, `DsoDeadlineJob`). Traces to giant Tasks 14, 15, 16.

## Scope

### In Scope

- DSO verzoek → case mapping + auto-create on ObjectCreatedEvent.
- Status-change event dispatch for OpenConnector pushback to DSO-LV.
- Daily deadline evaluation with 6-week/2-week warnings and overdue flagging.

### Out of Scope

- DSO settings admin UI (member 09).
- Workflow templates (member 01/02).

## Dependencies

- **vth-workflow-configuration-02-workflow-templates**: the Omgevingsvergunning workflow that intake creates cases against.

## Acceptance Criteria

1. GIVEN a DSO verzoek object, WHEN the listener triggers, THEN a case is auto-created with correct zaaktype and pre-filled mapped data.
2. GIVEN a case status change, WHEN executed, THEN a VergunningStatusChangedEvent is dispatched for OpenConnector to push to DSO-LV.
3. GIVEN DSO cases, WHEN the daily job runs, THEN deadline warnings fire at 6 and 2 weeks and overdue cases are flagged.
