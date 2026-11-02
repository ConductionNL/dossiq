# Tasks: creating-into-deliberation-runs-the-panel-check

### Task 1: Run the panel check on a create directly in in-deliberation
- **spec_ref**: `openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check`
- **files**: `lib/Listener/DeliberationStartGuard.php`, `lib/AppInfo/Registrar/BezwaarListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`
- [ ] Live: on a seeded instance, an API create of an advice request in `in-deliberation` with a conflicted panel is refused and nothing is saved

### Task 2: Close the remaining gaps around deliberation
- **spec_ref**: `openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-an-advice-request-reaches-advice-issued-or-inadmissible-only-from-in-deliberation`
- **files**: `lib/Listener/DeliberationStartGuard.php`, `lib/Service/Bezwaar/PanelIndependenceChecker.php`
- [x] A request without an objection is unverifiable
- [x] A panel or objection change during deliberation runs the check again
- [x] A save or create that skips deliberation is refused
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`, `tests/Unit/Service/Bezwaar/PanelIndependenceCheckerGoldenTest.php`
