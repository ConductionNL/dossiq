# Tasks: aanvullingsverzoek-as-a-record

Tier: V1. Kind: code. Size S. Row 1.17. Depends on
`pause-reason-with-chasing` for the `pauseReason` rows.

- [x] 1.1 `lib/Settings/register.d/60-termijnbewaking.json`: the
  `aanvullingsverzoek` schema with the case, the party, the `pauseReason`
  reference, the missing items, who asked and when, the hersteltermijn date
  and the state (D-1, D-2, D-3).
  - `@spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md`
- [x] 1.2 Declare the state transitions on the schema with
  `x-openregister-lifecycle` rather than in a service (D-4).
- [x] 2.1 The ask action on `#CaseDetail`: write the request, suspend the
  term through the existing path, arm the chases the reason declares.
  - `tests/unit/Service/AanvullingsverzoekServiceTest.php`
- [x] 2.2 The answer action: name which items arrived, close the request,
  resume the clock through `DeadlinePauseService::resumeAfterPauze`.
  - `tests/unit/Service/AanvullingsverzoekResolutionTest.php`
- [x] 2.3 A request whose hersteltermijn passes without an answer becomes
  `expired` from the engine timer, and stays readable (D-4).
- [x] 3.1 The derived open-request flag on the case, and the work list
  filter and count that read it (D-5).
  - `tests/vitest/caseListWaitingOnApplicant.spec.js`
- [x] 4.1 Dutch and English strings for the ask form, the item list, the
  states and the filter label.
- [x] 4.2 `tests/e2e/aanvullingsverzoek-as-a-record.spec.ts`: ask, chase,
  answer partly, answer fully, expire, filter the list;
  `openspec validate aanvullingsverzoek-as-a-record --strict`.
