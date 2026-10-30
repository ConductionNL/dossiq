# Tasks: plan-item-starts-next-working-day

### Task 1: A planned item starts at the next working day at 10:00
- **spec_ref**: `openspec/changes/plan-item-starts-next-working-day/specs/my-work/spec.md#requirement-a-planned-item-starts-at-the-next-working-day-at-1000`
- **files**: `lib/Service/Queue/PlannedItemDefaults.php`, `lib/Controller/PersonalQueueController.php`, `appinfo/routes.php`, `src/manifest.json`
- [x] Implement: defaults endpoint on the working-day calendar, dialog starts "When" from it
- [x] Test: `tests/Unit/Service/Queue/PlannedItemDefaultsTest.php`, `tests/vitest/planItemStartsNextWorkingDay.spec.js`
