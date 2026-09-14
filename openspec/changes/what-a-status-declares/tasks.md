# Tasks: what-a-status-declares

Tier: V1. Kind: code. Size M. Rows 2.43, 2.46, 8.25 and 10.18.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: `statusType.derivedWhen`, the
  conditions under which a status is true (D-1).
  - `@spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md`
- [ ] 1.2 A status with `derivedWhen` leaves the hand-picked transition list,
  and the case moves into it as the conditions become true (D-1).
  - `tests/unit/Service/DerivedStatusTest.php`
- [ ] 1.3 Show the unmet conditions on the case for a derivation that has not
  fired (D-2).
  - `tests/vitest/caseDerivedStatusReasons.spec.js`
- [ ] 2.1 `statusType.waitingOn` with the values `us`, `applicant` and
  `thirdParty`, beside the existing `role` (D-3).
  - `tests/unit/Service/StatusWaitingOnTest.php`
- [ ] 2.2 Team and queue counts read `waitingOn`, so a queue reports what is
  ours to move.
  - `tests/unit/Service/WorkQueueWaitingCountTest.php`
- [ ] 3.1 `statusType.maximumDwell` in working days, armed as an engine timer
  on entering the status and cancelled on leaving it (D-4).
  - `tests/unit/Service/StatusDwellTimerTest.php`
- [ ] 3.2 The breach is its own event with its own notification and filter,
  leaving the term untouched (D-5).
  - `tests/unit/Service/StatusDwellBreachTest.php`
- [ ] 4.1 Hold the current dwell and the total per status on the case,
  written as the status changes, counted on the working calendar (D-6, D-7).
  - `tests/unit/Service/CaseDwellFieldsTest.php`
- [ ] 4.2 Sort and filter the work list on the held numbers.
  - `tests/vitest/caseListDwellColumn.spec.js`
- [ ] 4.3 `lib/Service/ProcessMining/DwellTimeAnalyzer.php` reads the held
  numbers rather than recomputing its own, so the page and the list cannot
  disagree (D-6).
  - `tests/unit/Service/ProcessMining/DwellTimeAnalyzerTest.php`
- [ ] 5.1 Dutch and English strings for the derivation reasons, the waiting
  values, the dwell breach and the list column.
- [ ] 5.2 `tests/e2e/what-a-status-declares.spec.ts`: a derived status that
  fires and one that explains itself, a queue count by who we wait on, a
  dwell breach inside a healthy term, a work list sorted by dwell;
  `openspec validate what-a-status-declares --type change --strict`.
