# Tasks: only-the-advice-transition-sets-the-outcome

### Task 1: Only the advice transition sets advice-issued or inadmissible
- **spec_ref**: `openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible`
- **files**: `lib/Service/InternalWriteMarker.php`, `lib/Service/Bezwaar/AdvisoryCommitteeService.php`, `lib/Listener/DeliberationStartGuard.php`, `lib/AppInfo/Registrar/BezwaarListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`
- [ ] Live: on a seeded instance, an API write of `status: advice-issued` is refused and the advice request page still issues the advice
