# Tasks: creating-into-deliberation-runs-the-panel-check

### Task 1: Run the panel check on a create directly in in-deliberation
- **spec_ref**: `openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check`
- **files**: `lib/Listener/DeliberationStartGuard.php`, `lib/AppInfo/Registrar/BezwaarListenerRegistrar.php`
- [x] Implement
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`
- [x] Live: on a seeded instance, an API create of an advice request in `in-deliberation` with a conflicted panel is refused and nothing is saved
  - Live pass 2026-11-20 (throwaway rig, NC 34.0.6 with dossiq development 5b6450ef77, openregister main ce0543d6 and decidiq development e81adfb): tests/e2e/bezwaar-deliberation-guards.spec.ts, 'a create straight into in-deliberation with a conflicted panel is refused and nothing is saved', passed: a 4xx naming the panel member conflict, and no advice request stored.

### Task 2: Close the remaining gaps around deliberation
- **spec_ref**: `openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-an-advice-request-reaches-advice-issued-or-inadmissible-only-from-in-deliberation`
- **files**: `lib/Listener/DeliberationStartGuard.php`, `lib/Service/Bezwaar/PanelIndependenceChecker.php`
- [x] A request without an objection is unverifiable
- [x] A contested decision without a recorded author is unverifiable
- [x] A missing objection record, a missing `contestedDecision` and an unresolved decision are unverifiable
- [x] Authors and panel members are compared trimmed; a blank-only panel is refused
- [x] A panel or objection change during deliberation runs the check again
- [x] A save or create that skips deliberation is refused
- [x] Test: `tests/Unit/Listener/DeliberationStartGuardTest.php`, `tests/Unit/Service/Bezwaar/PanelIndependenceCheckerGoldenTest.php`
