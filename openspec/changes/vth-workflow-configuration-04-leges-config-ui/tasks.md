# Tasks: vth-workflow-configuration-04-leges-config-ui

Admin UI for leges rule sets. Traces to giant Task 5.

## 1. Components

- [~] Create `LegesRulesTab.vue` showing the list of rule sets — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `LegesRuleEditor.vue` with base fee, modifiers list, exemptions, verrekening, teruggaaf fields — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement rule validation (amounts ≥ 0, no duplicate modifiers) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Versioning & Tests

- [~] On save, call the leges service to version the rule set — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test rule versioning (old → validUntil, new → validFrom) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test effective date calculation (tomorrow) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test existing-case calculation remains on the old rule version — deferred to downstream cycle / fleet-wide adoption (handoff)
