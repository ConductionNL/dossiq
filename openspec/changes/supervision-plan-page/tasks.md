# Tasks: supervision-plan-page

### Task 1: The plan in the register
- **spec_ref**: `openspec/changes/supervision-plan-page/specs/vth-module/spec.md#requirement-the-supervision-plan-has-a-page-of-its-own`
- **files**: `lib/Settings/register.d` (supervisionPlan, supervisionPlanCategory), `tests/schemas/schema-version-digests.json`
- [x] Schemas with year, category, planned, capacity hours and frequency per risk profile
  - `lib/Settings/register.d/99-supervision-plan.json`: `supervisionPlan` (title, year, capacityHours) and `supervisionPlanCategory` (plan, name, planned, hoursPerInspection, riskFrequency of profile/objects/visitsPerYear, order); `fieldInspection` 1.3.0 gains `planCategory` and `cancelledAt`. Config keys in `SchemaSlugMap`. Digests recorded. Test: `tests/Unit/Settings/ZuiddrechtSupervisionPlanTest.php`.
- [x] Completed and cancelled counted from inspection records, not stored
  - `SupervisionPlanProgress` counts the field inspections naming a category (`completedAt` is completed, `cancelledAt` cancelled), per category and per quarter, with demand from the risk frequency; `GET /api/supervision-plans/{id}/progress` (`SupervisionPlanController`) reads them in the reader's own scope. Tests: `SupervisionPlanProgressTest` (the two scenarios), `SupervisionPlanControllerTest`.
- [x] Example-set data for Zuiddrecht 2026 (hospitality, construction, environment)
  - `lib/Settings/example-sets/zuiddrecht.d/68-supervision-plan-2026.json`: 40, 60 and 50 planned, 1,600 capacity hours, each category's risk demand equal to its plan. No inspections are tied to a category yet, so the page reads Completed 0 until inspections name one.

### Task 2: The page
- **spec_ref**: `openspec/changes/supervision-plan-page/specs/vth-module/spec.md#requirement-the-supervision-plan-has-a-page-of-its-own`
- **files**: `src/manifest.d` (page `/inspection-plan`, menu entry for the supervision role), `l10n/en.json`, `l10n/nl.json`
- [ ] Header, Year | Quarter, the four figures, the per-category bars, the quarterly overview, frequency by risk and the map, as board DqToezichtplan
- [ ] Test: vitest over the page config

### Task 3: Suggest a schedule
- **spec_ref**: `openspec/changes/supervision-plan-page/specs/vth-module/spec.md#requirement-a-schedule-is-proposed-before-it-is-written`
- **files**: an endpoint for the proposal, its dialog
- [ ] Proposal from frequency and capacity, shown before writing
  - Half: `GET /api/supervision-plans/{id}/schedule-proposal[?from=Y-m-d]` (`SupervisionScheduleProposal`) proposes dates per category from the risk frequency, cut evenly to the capacity, on working days, highest risk first, and writes nothing (`written: false`). The dialog that shows it waits on the page (task 2), and accepting a proposal (planning the inspections) is not built: a `fieldInspection` needs a case and an inspector, which the proposal does not choose.
- [x] Test: PHPUnit for the proposal rule
  - `tests/Unit/Service/Inspection/SupervisionScheduleProposalTest.php`.

### Task 4: Live pass
- [ ] compdiff of DqToezichtplan on the demo stack
