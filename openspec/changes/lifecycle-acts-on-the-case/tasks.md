# Tasks: lifecycle-acts-on-the-case

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 24, candidates
C-case-core-21, C-case-core-31, C-case-core-40 (matrix hole, its hold and
park half), C-case-core-29, C-case-core-7, C-case-core-35, C-case-core-41,
C-case-core-42, C-intake-2 and C-case-core-5. Decision D6 admits all three
`must` candidates on relevance. The acts that move a case through the
engine wait on openregister#3679, the provider write half.

- [x] 1.1 Filter `case.statusHiddenInLists` on every Cases lens, the Queue
  page, My Work, the open counts and the dashboard tiles, not only the
  All chip; leave search, the case page and an explicit status filter
  alone (D-4).
  - `tests/vitest/hiddenStatusList.spec.js`
  - `@spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md`
- [x] 1.2 `tests/unit/Architecture/DeclaredDisplayFlagHasReaderTest.php`:
  every declared display flag has a reader directly or through a declared
  calculation, or a reason-bearing allowlist entry, failing in both
  directions (D-4).
- [x] 2.1 One lifecycle menu on `CaseDetail`, drawn from
  `CaseActionProvider`, with the header actions folded into it and a
  refused act shown, disabled and carrying the guard's sentence (D-1).
  - `tests/vitest/caseLifecycleMenu.spec.js`
- [x] 2.2 An act the handler's role forbids is disabled and names the role
  (D-1).
  - `tests/unit/Service/CaseLifecycleServiceTest.php`
- [x] 3.1 Split ending into finish, abort, archive and reopen, each with
  its own permission and recorded reason (D-2).
  - `tests/unit/Service/CaseEndingActsTest.php`
- [x] 3.2 Archiving writes the retention rule from the result type, into
  the handover `archief-edepot-handover` specifies (D-2).
- [x] 3.3 Reopening keeps the ending in the record, with who and when
  (D-2).
- [x] 4.1 Close before the phases are complete, recording the result and
  the skipped phases, with every result guard still running (D-3).
  - `tests/unit/Service/EarlyCloseTest.php`
- [x] 4.2 The preset-outcome close as the same act with the outcome
  filled and the reason still recorded (D-3).
- [x] 5.1 `caseType`: declare process-owned status; refuse a direct status
  write where it is declared, and fail closed when the process cannot be
  resolved (D-5).
  - `tests/unit/Service/ProcessOwnedStatusTest.php`
- [x] 6.1 `caseType`: declare a silence period, off by default, warning
  the applicant through the declared moments before closing, and recording
  that the product closed it (D-6).
  - `tests/unit/BackgroundJob/AutoCloseOnSilenceJobTest.php`
- [x] 7.1 Knowing incompleteness: create with a required field empty,
  record the state, name the fields, and refuse the acts that need them
  (D-7).
  - `tests/unit/Service/CaseIncompletenessTest.php`
  - `tests/vitest/incompleteCaseBadge.spec.js`
- [x] 8.1 The draft case: no term, out of every list and count, visible to
  its author only, promoted in one act that keeps the draft moment (D-8).
  - `tests/unit/Service/DraftCaseTest.php`
- [x] 8.2 Share the exclusion rule with the case template in
  `starter-content-and-templates`, so one rule serves both (D-8).
  - Shipped as ONE spelling rather than one mechanism, and the difference is
    worth stating. The exclusion is `isDraft: false`, written into every
    working lens, the Queue, My Work, the dashboard tiles and
    `KpiAggregationService::OPEN_WORK`. `starter-content-and-templates` is not
    open, so there is no second reader to share a helper with yet; when it
    lands it adopts this field rather than declaring its own, which is the
    one rule the design asked for. Naming that here rather than claiming a
    shared abstraction that does not exist.
- [x] 9.1 Hold and park: a reason, a wake date, a marker wherever the case
  is listed, and no effect on any statutory term (D-9).
  - `tests/unit/Service/CaseHoldTest.php`
- [x] 9.2 Record the eight verbs of C-case-core-40 that belong elsewhere,
  with the change that carries each, in the proposal's table, and hand
  openregister#3679 the provider write half.
  - The table is in the proposal and stands. On #3679 the proposal is out of
    date, and the correction is the useful part. It says the write half "has
    no slug in the register's `changes_by_repo`" and that a follow-up lane
    should open it. **openregister#3679 is CLOSED**, fixed by
    openregister#3682, which added `execute()` to
    `LifecycleActionProviderInterface` and the provider branch in
    `applyTransition()`. dossiq's `CaseActionProvider` had implemented
    `execute()` in full for months, so the only thing that had ever been
    missing was OpenRegister's declaration. Nothing in this change waits on
    anything, and dossiq's local stub of that interface is updated here so the
    analysers see the real contract rather than the pre-#3682 one.
- [x] 9.3 Dutch and English strings.
- [x] 9.4 `tests/e2e/lifecycle-acts-on-the-case.spec.ts`: a hidden status
  emptying the list while search still finds it, the one menu with a
  disabled refused act, an abort that is not a besluit, an archive that
  writes retention, a reopen that keeps the ending, an early close, an
  incomplete phone intake, a private draft promoted, and a hold that does
  not stop the clock;
  `openspec validate lifecycle-acts-on-the-case --strict`.
