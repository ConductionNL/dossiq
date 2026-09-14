# Tasks: intake-says-when-the-term-starts

Tier: V1. Kind: code. Row Q8.21. Reads openregister
`working-calendar-admin` (to be specified) through dossiq
`terms-on-the-engine-calendar` (open); until that lands,
`WorkingDayCalculator` answers.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: `case.receivedAt`,
  `case.termStartsAt`, `case.receivedOutsideWorkingHours` (D-1, D-2).
- [ ] 1.2 Populate all three at case creation from the engine calendar,
  through one call (D-3).
  - `tests/Unit/Service/IntakeTermStartTest.php`
  - `@spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md`
- [ ] 2.1 The on-screen intake confirmation names the reference, the
  received moment, the start and the deadline (D-5).
  - `src/manifest.json`, `tests/vitest/intakeConfirmation.spec.js`
- [ ] 2.2 The ontvangstbevestiging names the same four, with the extra
  sentence only when the two moments differ (D-4).
  - `lib/Service/TermijnNotificationService.php`,
    `lib/Service/EmailTemplateService.php`
- [ ] 2.3 Dutch and English strings for both surfaces (ADR-025).
- [ ] 3.1 `tests/e2e/intake-says-when-the-term-starts.spec.ts`, filing
  inside and outside the working window; `openspec validate
  intake-says-when-the-term-starts --strict`.
