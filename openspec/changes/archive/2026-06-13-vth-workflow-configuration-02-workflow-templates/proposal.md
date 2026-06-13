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

# VTH Workflow Configuration — 02 Workflow Templates

> Member 2 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 01 (which declares the workflow template JSON). This member adds `VTHWorkflowService` to register and activate those templates idempotently.

## Summary

Implement `VTHWorkflowService`, the service that loads, registers, and activates the three VTH workflow templates declared by member 01 (Omgevingsvergunning, Toezichtzaak, Handhavingszaak). Activation creates the statuses and roles defined in each template and is idempotent on re-activation. Traces to giant Tasks 1, 2, 3 (service portion).

## Scope

### In Scope

- `VTHWorkflowService.loadTemplate()` / activation logic for all three templates.
- Idempotent re-activation (no duplicate statuses/roles created).
- Template-validation invocation against the JSON shipped in member 01.

### Out of Scope

- The template JSON itself (member 01).
- Leges, beschikking, mobile, LHSO, DSO, admin UI, tests, docs (members 03–11).

## Dependencies

- **vth-workflow-configuration-01-config-foundation**: provides the template JSON and repair-step registration this service reads.

## Acceptance Criteria

1. GIVEN the templates from member 01, WHEN an admin activates the Omgevingsvergunning template, THEN its statuses and roles are created.
2. GIVEN an already-activated template, WHEN it is activated again, THEN no duplicate statuses or roles are created.
