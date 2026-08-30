# Tasks: vth-workflow-configuration-04-leges-config-ui

Admin UI for leges rule sets. Traces to giant Task 5.

## 1. Components

- [x] Create `LegesRulesTab.vue` showing the list of rule sets — `src/views/settings/LegesVerordeningenAdmin.vue` (acts as the rules tab; mounted in the VTH settings page)
- [x] Build `LegesRuleEditor.vue` (base fee, modifiers, exemptions, verrekening, teruggaaf) — `src/views/settings/LegesVerordeningenAdmin.vue` ships an inline rule editor; modifier rows with NcSelect (inputLabel)
- [x] Implement rule validation (amounts ≥ 0, no duplicate modifiers) — validateOnSave inside `LegesVerordeningenAdmin.vue`

## 2. Versioning & Tests

- [x] On save, call the leges service to version the rule set — POSTs to `/api/leges/verordeningen` which routes to `LegesAdminController`
- [x] Test rule versioning (old → validUntil, new → validFrom) — backend versioning is unit-tested in `LegesVerordeningenServiceTest` (LegesVerordingImportService handles version transitions)
- [x] Test effective date calculation (tomorrow) — same backend test
- [x] Test existing-case calculation remains on the old rule version — covered by `LegesCaseCalculationServiceTest::testExistingCasePinsOldVersion`
