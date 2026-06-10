# Tasks: vth-workflow-configuration-04-leges-config-ui


> **Build status (hydra audit 2026-06-10).** Frontend admin UI for leges. Backend (`LegesVerordingImportService`, `LegesController`, `LegesAdminController`) ships on dev. The Vue admin pages (tariff-table import wizard, version diff, audit log viewer) remain greenfield.
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
