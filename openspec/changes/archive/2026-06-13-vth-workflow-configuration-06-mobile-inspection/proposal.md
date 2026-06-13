---
kind: code
depends_on:
  - vth-workflow-configuration-01-config-foundation
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

# VTH Workflow Configuration — 06 Mobile Inspection

> Member 6 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 01 (Toezichtzaak template + checklists). Implements the mobile inspection service and the responsive field UI. The checklist-template configuration UI (giant Task 10) is delivered in member 09 (admin settings).

## Summary

Implement `MobileInspectionService` + `MobileInspectionController` (mobile-formatted checklist retrieval, photo upload to Nextcloud, GPS capture with fallback, inspection-result submission with validation) and the responsive field UI (`MobileInspectionView.vue` and its item/photo/GPS components with progress, navigation, and offline draft sync). Traces to giant Tasks 8, 9.

## Scope

### In Scope

- Mobile inspection service + controller endpoints.
- Responsive `MobileInspectionView` and `MobileChecklistItem`, `PhotoUploadInput`, `GpsLocationInput` components.
- Offline draft answers with sync on reconnect.

### Out of Scope

- Checklist-template configuration UI (giant Task 10 → member 09).
- Toezichtzaak template/checklist seed (member 01).

## Dependencies

- **vth-workflow-configuration-01-config-foundation**: provides Toezichtzaak template and inspection checklists.

## Acceptance Criteria

1. GIVEN an inspector on mobile, WHEN they open a toezichtzaak, THEN a responsive checklist with type-specific inputs, photo upload, and GPS tagging is shown.
2. GIVEN a submission, WHEN required items/photos are present, THEN an InspectionResult is created; otherwise a validation error is returned.
