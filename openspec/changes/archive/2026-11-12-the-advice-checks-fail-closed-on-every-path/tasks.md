# Tasks: the-advice-checks-fail-closed-on-every-path

### Task 1: Fail closed without a store, and mark the save under the stored id
- **spec_ref**: `openspec/changes/the-advice-checks-fail-closed-on-every-path/specs/bezwaar-advisory-committee/spec.md#requirement-the-panel-check-and-the-advice-transition-fail-closed-on-every-path`
- **files**: `lib/Service/Bezwaar/PanelIndependenceChecker.php`, `lib/Service/Bezwaar/AdvisoryCommitteeService.php`
- [x] Implement
- [x] Test: `tests/Unit/Service/Bezwaar/PanelIndependenceCheckerGoldenTest.php`, `tests/Unit/Listener/DeliberationStartGuardTest.php`
