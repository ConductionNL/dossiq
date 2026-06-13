---
kind: code
depends_on:
  - vth-workflow-configuration-03-leges-engine
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

# VTH Workflow Configuration — 04 Leges Config UI

> Member 4 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 03 (the leges engine). Adds the admin UI for editing leges rule sets with versioning.

## Summary

Implement the leges rule-configuration UI: `LegesRulesTab.vue` and `LegesRuleEditor.vue`, letting admins edit base fees, modifiers, exemptions, verrekening and teruggaaf rules. On save, a new rule version is created (prior marked validUntil=today, new validFrom=tomorrow). Traces to giant Task 5.

## Scope

### In Scope

- `LegesRulesTab` list view of rule sets.
- `LegesRuleEditor` form with validation and rule versioning on save.

### Out of Scope

- Calculation engine (member 03).
- Mounting the tab inside the VTH settings page shell (member 09).

## Dependencies

- **vth-workflow-configuration-03-leges-engine**: provides the leges service the editor versions against.

## Acceptance Criteria

1. GIVEN the editor, WHEN an admin edits base fee/modifiers/exemptions/verrekening/teruggaaf and saves, THEN a new rule version is created and the prior is marked validUntil=today.
2. GIVEN a save, WHEN versioning completes, THEN the UI confirms the new rules are effective for new cases from tomorrow.
