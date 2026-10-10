# Tasks: one-term-engine

Ruben's decision of 2026-10-09 (screen dossiq/DqMijnWerk). Kind: code. Each
group lands on `development` as its own PR.

## 1. Engine, write-back and the start at receipt (PR 1)

- [x] 1.1 `CaseDeadlineMirror` (`lib/Service/Termijn/`): which statutory
  instance decides a case's deadline, and `follow()` that writes it onto the
  case when it differs (D-1, REQ-OTE-01).
  - `tests/Unit/Service/Termijn/CaseDeadlineMirrorTest.php`
- [x] 1.2 `TermijnService` calls the mirror after every save of a statutory
  instance (create, `saveTermInstance()`, `updateTermijnInstance()`).
  - `tests/Unit/Service/TermijnServiceTest.php`
- [x] 1.3 `CaseDeadlineFollowsTermListener` on `ObjectUpdatingEvent`, priority
  -110, puts the instance's date into every case save (D-1).
  - `tests/Unit/Listener/CaseDeadlineFollowsTermListenerTest.php`
- [x] 1.4 `DeadlineCaseCreatedListener` starts the statutory instance and the
  other clocks at `termStartsAt` (D-3, REQ-OTE-02).
  - `tests/Unit/Listener/DeadlineCaseCreatedListenerTest.php`
- [x] 1.5 `DeadlineExtensionService` rolls the requested end date and keeps
  `endDateBeforeRoll`; declare it on `deadlineInstance` (D-5, REQ-OTE-03).
  - `tests/Unit/Service/DeadlineExtensionLimitTest.php`
- [x] 1.6 Repair step `ReconcileCaseDeadlinesWithTerms`, part one: every case's
  deadline follows its statutory instance (D-9, REQ-OTE-08).
  - `tests/Unit/Repair/ReconcileCaseDeadlinesWithTermsTest.php`
- [x] 1.7 The `deadline` property and calculation descriptions say what is now
  true (fallback only); register version bumped.

## 2. Working days and the work queue (PR 2)

- [ ] 2.1 `CaseTermsService::endAfter()` takes a mode; the planned end and the
  internal target count working days through `WorkingDayRoll` (D-5, REQ-OTE-04).
  - `tests/Unit/Service/CaseTermsServiceTest.php`
- [ ] 2.2 `WorkQueueService::businessDaysBetween()` asks `WorkingDayRoll`,
  Monday to Friday only as the logged fallback (REQ-OTE-04).
- [ ] 2.3 `WorkQueueService::nearestActiveTermDeadline()` reads `lopend`,
  `verlengd`, `paused` and `exceeded` (D-8, REQ-OTE-06).
  - `tests/Unit/Service/WorkQueueServiceTest.php`

## 3. Late means the day after (PR 3)

- [ ] 3.1 `TermijnTimerService::armBeslistermijn()` anchors at the start of the
  day after the start day, so it breaches the day after the end day (D-6,
  REQ-OTE-05).
  - `tests/Unit/Service/TermijnTimerServiceTest.php`
- [ ] 3.2 Repair step part two: re-arm running beslistermijn timers once, mark
  `timerBreachesAfterLastDay` (D-9, REQ-OTE-08).
- [ ] 3.3 One front-end helper (`src/utils/deadlineCountdown.js`), used by
  `caseHelpers`, `caseTerms`, `dashboardHelpers`, `WooDeadlinePanel` and
  `MyWorkCaseCard` (D-7, REQ-OTE-07).
  - `tests/vitest/deadlineCountdown.spec.js`
- [ ] 3.4 Simple structure: list column, board `dueRule` and week strip
  `lateWhen` read `lt 0` as late (D-6, REQ-OTE-05).
  - `tests/vitest/manifestDueRules.spec.js`

## 4. Woo on the generic engine (PR 4)

- [ ] 4.1 `WOODeadlineService::calculate()` asks the definitions
  (`td-woo-verzoek`) for the end date; no own constants (D-4).
- [ ] 4.2 `extendDeadline()` goes through `DeadlineExtensionService` on the
  case's statutory instance; no undeclared keys; 409 on a second extension.
  - `tests/Unit/Service/WOODeadlineServiceTest.php`
- [ ] 4.3 The T-7 warning reads `deadline` only; drop `verdagingReden` from the
  Woo template's field list where it was written as case data.
- [ ] 4.4 `woo-term-is-computed-and-reported-right` tasks 1 to 3 marked as moved
  here.

## 5. Verify and deliver

- [ ] 5.1 Per PR: php -l, phpcs and phpstan on the changed PHP, eslint on the
  changed JS, the touched unit tests; once before push
  `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` with `TMPDIR` inside the
  clone, `npm run lint`, `npm run test:l10n`.
- [ ] 5.2 Boards: DqMijnWerk's week strip draws the term ending today as late,
  and DqTermijnen's "Verlopen" tile says "Beslis vandaag of verdaag". Reported
  to the design-system owner; not edited here.
