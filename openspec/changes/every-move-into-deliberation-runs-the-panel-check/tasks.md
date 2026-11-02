# Tasks: every-move-into-deliberation-runs-the-panel-check

### Task 1: Run the panel check on every write that starts deliberation
- **spec_ref**: `openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-write-that-starts-deliberation-runs-the-panel-check`
- **files**: `lib/Listener/DeliberationStartGuard.php`, `lib/Service/Bezwaar/AdvisoryCommitteeService.php`, `lib/AppInfo/Registrar/BezwaarListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`, `tests/Unit/Service/Bezwaar/AdvisoryCommitteeServiceTest.php`
- [ ] Live: on a seeded instance, the advice request page's lifecycle action refuses a conflicted panel and the history tab shows the entry
