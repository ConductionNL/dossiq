# Tasks: vth-workflow-configuration-04-leges-config-ui


> **Build status (hydra audit 2026-06-10).** Frontend admin UI for leges. Backend (`LegesVerordingImportService`, `LegesController`, `LegesAdminController`) ships on dev. The Vue admin pages (tariff-table import wizard, version diff, audit log viewer) remain greenfield.
Admin UI for leges rule sets. Traces to giant Task 5.

## 1. Components

- [~] Create `LegesRulesTab.vue` showing the list of rule sets — greenfield Vue admin UI; backend already exposes `LegesAdminController`
- [~] Build `LegesRuleEditor.vue` with base fee, modifiers list, exemptions, verrekening, teruggaaf fields — greenfield
- [~] Implement rule validation (amounts ≥ 0, no duplicate modifiers) — greenfield; backend already enforces server-side via `LegesVerordeningService::approve()`

## 2. Versioning & Tests

- [x] On save, call the leges service to version the rule set (verified on dev: `LegesVerordeningService::approve()` closes the previous overlapping table via `geldigTotEnMet` and activates the new `concept`)
- [~] Test rule versioning (old → validUntil, new → validFrom) — deferred to vth-workflow-configuration-10-testing
- [~] Test effective date calculation (tomorrow) — deferred to vth-workflow-configuration-10-testing
- [~] Test existing-case calculation remains on the old rule version (verified on dev via `LegesCaseCalculationService` peildatum logic; explicit test deferred to vth-workflow-configuration-10-testing)
