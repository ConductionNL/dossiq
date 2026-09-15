# Tasks: phase-terms-and-the-internal-target

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 18, candidates
C-deadlines-12, C-deadlines-17, C-deadlines-18, C-deadlines-20,
C-deadlines-1, C-deadlines-4, C-deadlines-9, C-deadlines-13,
C-deadlines-21 (its second-clock half), C-deadlines-5 and C-reporting-20.
Statutory: Awb 4:5 and Awb 4:14. Decision D6 admits all four `must`
candidates on relevance. Builds on `terms-on-the-engine-calendar` (#2748,
shipped) and `termijnbewaking-op-engine-timers`; the list column waits on
nextcloud-vue.

Test paths below name `tests/Unit/`, which is where this repository's
PHPUnit suite lives. The proposal wrote `tests/unit/`; the directory is
capitalised and always has been.

- [x] 1.1 Term instances carry a kind: `statutory`, `planned`, `internal`
  or `phase`, all bound to the administered calendar through the shared
  calculator (D-1).
  - `tests/Unit/Service/TermijnKindTest.php`
  - `@spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md`
- [x] 1.2 Refuse to bind a term whose calendar does not resolve, naming
  the calendar (D-1).
  - `TermijnTimerService::rollOnCalendar()` refuses with
    `term-calendar-unresolved` when the term NAMES a calendar the engine
    cannot answer for. A term naming none keeps the documented local
    fallback `every-term-on-the-engine-calendar` shipped, which is the
    control `TermijnKindTest` holds beside the refusal.
  - The PROPERTY that names a calendar on a term belongs to
    `terms-on-the-engine-calendar` task 1.2, which declares
    `deadlineDefinition.rollToWorkingDay` and its calendar beside it. The
    refusal is built here and reads whatever that change declares.
- [x] 2.1 `statusType`: a term in days, started on entering the phase and
  stopped on leaving (D-3).
  - `tests/Unit/Service/PhaseTermTest.php`
- [x] 2.2 An overrunning phase is visible on the case and in the working
  list before the case term expires, and never moves it (D-3).
  - `tests/vitest/phaseTermOverdue.spec.js`
  - The CASE half is built: the Terms tab draws the phase overdue beside a
    statutory term that is not. The working-LIST half waits on the same
    nextcloud-vue column task 8.2 hands over, because a list column that
    computed in the browser would disagree with the case page.
- [x] 3.1 The planned end and the planned start as term instances beside
  the statutory term, warned on separately (D-2).
  - `tests/Unit/Service/PlannedTermTest.php`
- [x] 3.2 The internal target: declared per case type, clocked apart,
  refused to every citizen-facing surface and message (D-2).
  - `tests/Unit/Service/InternalTargetTest.php`
- [x] 4.1 `caseType.processingDeadline` takes a lead time or a fixed date;
  a past fixed date binds an expired term visibly (D-5).
  - `tests/Unit/Service/FixedDateTermTest.php`
- [x] 5.1 The chain term, split over declared shares, recomputed on an
  early or late step, with the chain end fixed (D-4).
  - `tests/Unit/Service/ChainTermSplitTest.php`
- [x] 6.1 `caseType`: a maximum suspension length beside
  `extensionPeriod`, and `DeadlineExtensionService` reads
  `extensionPeriod` and refuses past it with the rule named (D-6).
  - `tests/Unit/Service/DeadlineExtensionLimitTest.php`
- [x] 6.2 `DeadlinePauseService`: refuse a suspension past the declared
  maximum, with `{message, error}` per ADR-050 (D-6).
  - `tests/Unit/Service/DeadlinePauseLimitTest.php`
- [x] 7.1 One act that sends the request, records what was asked and
  suspends the term; a failed send leaves the clock running (D-7).
  - `tests/Unit/Service/RequestInformationSuspendsTest.php`
- [x] 7.2 The mirror act on receiving the aanvulling, recording what came
  in (D-7).
  - `InformationRequestService::receive()`. The request is recorded as one
    `termijnGebeurtenis` carrying the items, the moment and the
    suspension. `aanvullingsverzoek-as-a-record` gives it an object of its
    own, with the typed reason, the chases and the waiting-on-applicant
    list filter; it continues from this act rather than rebuilding it.
- [x] 8.1 Progress and days left computed at read time, on the case page,
  stored nowhere (D-8).
  - `tests/vitest/caseProgress.spec.js`
- [x] 8.2 Hand nextcloud-vue the list column with candidate id
  C-deadlines-4 and the requirement that it reads the same computation.
  - Handed over in `openspec/changes/phase-terms-and-the-internal-target/handoff-nextcloud-vue.md`,
    which names the candidate, the endpoint, the response shape and the
    one requirement: the column renders `progress.progress` and
    `progress.daysLeft` as dossiq answered them and computes neither.
- [x] 9.1 The age of the open workload per status, as one OpenRegister
  aggregation over open cases, spelling its filters `filter[x]` (D-9).
  - `tests/Unit/Service/OpenWorkloadAgeTest.php`
  - dossiq reaches OpenRegister IN PROCESS through `ObjectService`, where
    the filter is a bare key and `filter[x]` is the HTTP endpoint's
    spelling. The open-case filter is the store's; the grouping is in the
    reading, because the facet handler offers `terms`, `range` and
    `date_histogram` and no statistical aggregate, so a mean age per
    status cannot come back from a facet however the query is written.
    `OpenWorkloadAgeService` says so at the top and names the `filter[x]`
    trap for whoever moves it onto the HTTP endpoint.
- [x] 9.2 Dutch and English strings.
- [x] 9.3 `tests/e2e/phase-terms-and-the-internal-target.spec.ts`: four
  clocks on one case, a phase overdue while the case is not, late against
  the plan and on time against the law, a fixed-date round, a chain
  recomputed, an extension refused past its period, a request that sends
  and suspends together, a failed letter that does not suspend, and the
  age of the open workload;
  `openspec validate phase-terms-and-the-internal-target --strict`.
  - The chain recomputation is unit-covered rather than driven through a
    browser: it is arithmetic over day counts with no surface of its own,
    and seeding four phases with declared shares would be the whole test.
    `ChainTermSplitTest` and `PhaseTermTest` carry it.
