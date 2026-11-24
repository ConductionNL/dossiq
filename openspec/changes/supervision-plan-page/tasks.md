# Tasks: supervision-plan-page

### Task 1: The plan in the register
- **spec_ref**: `openspec/changes/supervision-plan-page/specs/vth-module/spec.md#requirement-the-supervision-plan-has-a-page-of-its-own`
- **files**: `lib/Settings/register.d` (supervisionPlan, supervisionPlanCategory), `tests/schemas/schema-version-digests.json`
- [ ] Schemas with year, category, planned, capacity hours and frequency per risk profile
- [ ] Completed and cancelled counted from inspection records, not stored
- [ ] Example-set data for Zuiddrecht 2026 (hospitality, construction, environment)

### Task 2: The page
- **spec_ref**: `openspec/changes/supervision-plan-page/specs/vth-module/spec.md#requirement-the-supervision-plan-has-a-page-of-its-own`
- **files**: `src/manifest.d` (page `/inspection-plan`, menu entry for the supervision role), `l10n/en.json`, `l10n/nl.json`
- [ ] Header, Year | Quarter, the four figures, the per-category bars, the quarterly overview, frequency by risk and the map, as board DqToezichtplan
- [ ] Test: vitest over the page config

### Task 3: Suggest a schedule
- **spec_ref**: `openspec/changes/supervision-plan-page/specs/vth-module/spec.md#requirement-a-schedule-is-proposed-before-it-is-written`
- **files**: an endpoint for the proposal, its dialog
- [ ] Proposal from frequency and capacity, shown before writing
- [ ] Test: PHPUnit for the proposal rule

### Task 4: Live pass
- [ ] compdiff of DqToezichtplan on the demo stack
