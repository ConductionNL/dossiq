# Tasks: doorlooptijd-granularity

### Task 1: The processing-time averages can be grouped per week, month, quarter or year
- **spec_ref**: `openspec/changes/doorlooptijd-granularity/specs/doorlooptijd-dashboard/spec.md#requirement-the-processing-time-averages-can-be-grouped-per-week-month-quarter-or-year`
- **files**: `lib/Controller/DoorlooptijdController.php`, `lib/Service/DoorlooptijdService.php`, `lib/Service/Doorlooptijd/DeadlineComplianceCalculator.php`, `lib/Service/Doorlooptijd/ThroughputBuckets.php`, `src/manifest.json`
- [x] Implement: the parameter, the grouping, the page switch
- [x] Test: `tests/Unit/Service/Doorlooptijd/ThroughputSummaryTest.php`, `tests/Unit/Controller/DoorlooptijdControllerContractTest.php`, `tests/vitest/doorlooptijdPeriodPresets.spec.js`
