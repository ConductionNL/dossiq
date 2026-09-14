# Tasks: lifecycle-acts-on-the-case

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 24, candidates
C-case-core-21, C-case-core-31, C-case-core-40 (matrix hole, its hold and
park half), C-case-core-29, C-case-core-7, C-case-core-35, C-case-core-41,
C-case-core-42, C-intake-2 and C-case-core-5. Decision D6 admits all three
`must` candidates on relevance. The acts that move a case through the
engine wait on openregister#3679, the provider write half.

- [ ] 1.1 Filter `case.statusHiddenInLists` on every Cases lens, the Queue
  page, My Work, the open counts and the dashboard tiles, not only the
  All chip; leave search, the case page and an explicit status filter
  alone (D-4).
  - `tests/vitest/hiddenStatusList.spec.js`
  - `@spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md`
- [ ] 1.2 `tests/unit/Architecture/DeclaredDisplayFlagHasReaderTest.php`:
  every declared display flag has a reader directly or through a declared
  calculation, or a reason-bearing allowlist entry, failing in both
  directions (D-4).
- [ ] 2.1 One lifecycle menu on `CaseDetail`, drawn from
  `CaseActionProvider`, with the header actions folded into it and a
  refused act shown, disabled and carrying the guard's sentence (D-1).
  - `tests/vitest/caseLifecycleMenu.spec.js`
- [ ] 2.2 An act the handler's role forbids is disabled and names the role
  (D-1).
  - `tests/unit/Service/CaseLifecycleServiceTest.php`
- [ ] 3.1 Split ending into finish, abort, archive and reopen, each with
  its own permission and recorded reason (D-2).
  - `tests/unit/Service/CaseEndingActsTest.php`
- [ ] 3.2 Archiving writes the retention rule from the result type, into
  the handover `archief-edepot-handover` specifies (D-2).
- [ ] 3.3 Reopening keeps the ending in the record, with who and when
  (D-2).
- [ ] 4.1 Close before the phases are complete, recording the result and
  the skipped phases, with every result guard still running (D-3).
  - `tests/unit/Service/EarlyCloseTest.php`
- [ ] 4.2 The preset-outcome close as the same act with the outcome
  filled and the reason still recorded (D-3).
- [ ] 5.1 `caseType`: declare process-owned status; refuse a direct status
  write where it is declared, and fail closed when the process cannot be
  resolved (D-5).
  - `tests/unit/Service/ProcessOwnedStatusTest.php`
- [ ] 6.1 `caseType`: declare a silence period, off by default, warning
  the applicant through the declared moments before closing, and recording
  that the product closed it (D-6).
  - `tests/unit/BackgroundJob/AutoCloseOnSilenceJobTest.php`
- [ ] 7.1 Knowing incompleteness: create with a required field empty,
  record the state, name the fields, and refuse the acts that need them
  (D-7).
  - `tests/unit/Service/CaseIncompletenessTest.php`
  - `tests/vitest/incompleteCaseBadge.spec.js`
- [ ] 8.1 The draft case: no term, out of every list and count, visible to
  its author only, promoted in one act that keeps the draft moment (D-8).
  - `tests/unit/Service/DraftCaseTest.php`
- [ ] 8.2 Share the exclusion rule with the case template in
  `starter-content-and-templates`, so one rule serves both (D-8).
- [ ] 9.1 Hold and park: a reason, a wake date, a marker wherever the case
  is listed, and no effect on any statutory term (D-9).
  - `tests/unit/Service/CaseHoldTest.php`
- [ ] 9.2 Record the eight verbs of C-case-core-40 that belong elsewhere,
  with the change that carries each, in the proposal's table, and hand
  openregister#3679 the provider write half.
- [ ] 9.3 Dutch and English strings.
- [ ] 9.4 `tests/e2e/lifecycle-acts-on-the-case.spec.ts`: a hidden status
  emptying the list while search still finds it, the one menu with a
  disabled refused act, an abort that is not a besluit, an archive that
  writes retention, a reopen that keeps the ending, an early close, an
  incomplete phone intake, a private draft promoted, and a hold that does
  not stop the clock;
  `openspec validate lifecycle-acts-on-the-case --strict`.
