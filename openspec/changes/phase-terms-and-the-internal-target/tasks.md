# Tasks: phase-terms-and-the-internal-target

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 18, candidates
C-deadlines-12, C-deadlines-17, C-deadlines-18, C-deadlines-20,
C-deadlines-1, C-deadlines-4, C-deadlines-9, C-deadlines-13,
C-deadlines-21 (its second-clock half), C-deadlines-5 and C-reporting-20.
Statutory: Awb 4:5 and Awb 4:14. Decision D6 admits all four `must`
candidates on relevance. Builds on `terms-on-the-engine-calendar` (#2748,
shipped) and `termijnbewaking-op-engine-timers`; the list column waits on
nextcloud-vue.

- [ ] 1.1 Term instances carry a kind: `statutory`, `planned`, `internal`
  or `phase`, all bound to the administered calendar through the shared
  calculator (D-1).
  - `tests/unit/Service/TermijnKindTest.php`
  - `@spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md`
- [ ] 1.2 Refuse to bind a term whose calendar does not resolve, naming
  the calendar (D-1).
- [ ] 2.1 `statusType`: a term in days, started on entering the phase and
  stopped on leaving (D-3).
  - `tests/unit/Service/PhaseTermTest.php`
- [ ] 2.2 An overrunning phase is visible on the case and in the working
  list before the case term expires, and never moves it (D-3).
  - `tests/vitest/phaseTermOverdue.spec.js`
- [ ] 3.1 The planned end and the planned start as term instances beside
  the statutory term, warned on separately (D-2).
  - `tests/unit/Service/PlannedTermTest.php`
- [ ] 3.2 The internal target: declared per case type, clocked apart,
  refused to every citizen-facing surface and message (D-2).
  - `tests/unit/Service/InternalTargetTest.php`
- [ ] 4.1 `caseType.processingDeadline` takes a lead time or a fixed date;
  a past fixed date binds an expired term visibly (D-5).
  - `tests/unit/Service/FixedDateTermTest.php`
- [ ] 5.1 The chain term, split over declared shares, recomputed on an
  early or late step, with the chain end fixed (D-4).
  - `tests/unit/Service/ChainTermSplitTest.php`
- [ ] 6.1 `caseType`: a maximum suspension length beside
  `extensionPeriod`, and `DeadlineExtensionService` reads
  `extensionPeriod` and refuses past it with the rule named (D-6).
  - `tests/unit/Service/DeadlineExtensionLimitTest.php`
- [ ] 6.2 `DeadlinePauseService`: refuse a suspension past the declared
  maximum, with `{message, error}` per ADR-050 (D-6).
  - `tests/unit/Service/DeadlinePauseLimitTest.php`
- [ ] 7.1 One act that sends the request, records what was asked and
  suspends the term; a failed send leaves the clock running (D-7).
  - `tests/unit/Service/RequestInformationSuspendsTest.php`
- [ ] 7.2 The mirror act on receiving the aanvulling, recording what came
  in (D-7).
- [ ] 8.1 Progress and days left computed at read time, on the case page,
  stored nowhere (D-8).
  - `tests/vitest/caseProgress.spec.js`
- [ ] 8.2 Hand nextcloud-vue the list column with candidate id
  C-deadlines-4 and the requirement that it reads the same computation.
- [ ] 9.1 The age of the open workload per status, as one OpenRegister
  aggregation over open cases, spelling its filters `filter[x]` (D-9).
  - `tests/unit/Service/OpenWorkloadAgeTest.php`
- [ ] 9.2 Dutch and English strings.
- [ ] 9.3 `tests/e2e/phase-terms-and-the-internal-target.spec.ts`: four
  clocks on one case, a phase overdue while the case is not, late against
  the plan and on time against the law, a fixed-date round, a chain
  recomputed, an extension refused past its period, a request that sends
  and suspends together, a failed letter that does not suspend, and the
  age of the open workload;
  `openspec validate phase-terms-and-the-internal-target --strict`.
