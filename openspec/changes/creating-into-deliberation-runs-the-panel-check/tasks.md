# Tasks: creating-into-deliberation-runs-the-panel-check

### Task 1: Run the panel check on a create directly in in-deliberation
- **spec_ref**: `openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check`
- **files**: `lib/Listener/DeliberationStartGuard.php`, `lib/AppInfo/Registrar/BezwaarListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`
- [ ] Live: on a seeded instance, an API create of an advice request in `in-deliberation` with a conflicted panel is refused and nothing is saved
