# Tasks: only-the-advice-transition-sets-the-outcome

### Task 1: Only the advice transition sets advice-issued or inadmissible
- **spec_ref**: `openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible`
- **files**: `lib/Controller/BezwaarAdviceRequestController.php`, `appinfo/routes.php`, `lib/Service/InternalWriteMarker.php`, `lib/Service/Bezwaar/AdvisoryCommitteeService.php`, `lib/Listener/DeliberationStartGuard.php`, `lib/AppInfo/Registrar/BezwaarListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`, `tests/Unit/Controller/BezwaarAdviceRequestControllerTest.php`, `tests/Unit/Service/InternalWriteMarkerTest.php`
- [x] Page: the advice request page's lifecycle action calls the endpoint (manifest, screens)
- [x] Live: on a seeded instance, an API write of `status: advice-issued` is refused and the advice request page still issues the advice
  - Live pass 2026-11-20 (throwaway rig, NC 34.0.6 with dossiq development 5b6450ef77, openregister main ce0543d6 and decidiq development e81adfb): tests/e2e/bezwaar-deliberation-guards.spec.ts passed: a plain write of advice-issued is refused with 'Only the advice transition', and Issue advice and Declare inadmissible on the page reach their outcome through the transition.
