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

# VTH Workflow Configuration — 05 Beschikking Generation

> Member 5 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 01 (which seeds beschikking templates). Implements permit-decision document generation and its template-management UI.

## Summary

Implement `BeschikkingGenerationService` + `BeschikkingController` (template-based permit/decision generation with merge-field substitution, required-field validation, template versioning, PDF output) and the admin template-management UI (`BeschikkingTemplatesTab.vue`, `BeschikkingTemplateEditor.vue`). Traces to giant Tasks 6, 7.

## Scope

### In Scope

- Generation service: load template, validate required fields, merge-field substitution, HTML/PDF output, attach to case.
- Generation controller endpoint.
- Template-management UI with merge-field picker, test generation, validity dates.

### Out of Scope

- Beschikking template seed data (member 01).
- Mounting the templates tab in the settings shell (member 09).

## Dependencies

- **vth-workflow-configuration-01-config-foundation**: provides seeded beschikking templates.

## Acceptance Criteria

1. GIVEN a case with all required fields, WHEN generated, THEN a beschikking PDF is produced with merged fields and attached to the case.
2. GIVEN a missing required field, WHEN generation runs, THEN it is blocked with a field-named error.
3. GIVEN an admin, WHEN they create/edit a template with validity dates, THEN new generations use only the current version.
