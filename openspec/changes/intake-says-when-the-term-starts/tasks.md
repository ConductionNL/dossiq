# Tasks: intake-says-when-the-term-starts

Tier: V1. Kind: code. Row Q8.21.

`terms-on-the-engine-calendar` LANDED while this change waited (#2941), so the
engine calendar is reachable today and `WorkingDayCalculator` never had to
answer. `WorkingDayRoll` already resolves `SlaCalculator` and the organisation
calendar; this change adds one method to it rather than a second resolver.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `case.receivedAt`,
  `case.termStartsAt`, `case.receivedOutsideWorkingHours`, all read-only, with
  the `case` schema at 1.27.0.
- [x] 1.2 `WorkingDayRoll::firstWorkingMomentAtOrAfter()` and
  `lib/Service/IntakeTermStart.php` compute the three through the one calendar
  the term counts on (D-3). NOT `roll()`: that keeps the clock time it is given,
  because a term ends at the end of its day, and asking it for an intake start
  answers nine in the evening on Monday for a Sunday-evening filing.
  - `tests/Unit/Service/IntakeTermStartTest.php`
- [x] 1.3 `lib/Listener/IntakeTermStartListener.php` on `ObjectCreatedEvent`,
  registered in `WorkflowListenerRegistrar`. On the event and not in a service
  because a case reaches dossiq from six intake paths and only two of them run
  a dossiq service.
- [x] 2.1 `GET /api/case/{caseId}/intake-confirmation` answers the four and the
  sentence, guarded by `CaseAccessGuard` like the receipt duty beside it (D-5).
- [x] 2.2 The shipped ontvangstbevestiging names the same four, from the same
  producer (`IntakeConfirmation::placeholdersFor`). The one conditional sentence
  is a placeholder that resolves to nothing when no explanation is due, because
  a template is a flat string with no `if` (D-4).
- [x] 2.3 The sentence ships in Dutch and English; catalogues sorted.
- [x] 3.1 `tests/e2e/intake-says-when-the-term-starts.spec.ts`; `openspec
  validate intake-says-when-the-term-starts --strict`.
