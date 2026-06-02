# Tasks: vth-workflow-configuration-04-leges-config-ui

Admin UI for leges rule sets. Traces to giant Task 5.

## 1. Components

- [ ] Create `LegesRulesTab.vue` showing the list of rule sets
- [ ] Build `LegesRuleEditor.vue` with base fee, modifiers list, exemptions, verrekening, teruggaaf fields
- [ ] Implement rule validation (amounts ≥ 0, no duplicate modifiers)

## 2. Versioning & Tests

- [ ] On save, call the leges service to version the rule set
- [ ] Test rule versioning (old → validUntil, new → validFrom)
- [ ] Test effective date calculation (tomorrow)
- [ ] Test existing-case calculation remains on the old rule version
