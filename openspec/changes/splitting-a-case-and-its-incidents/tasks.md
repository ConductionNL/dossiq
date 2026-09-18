# Tasks: splitting-a-case-and-its-incidents

Tier: V1. Kind: code. Size M. Rows 2.35 and 2.45.

The dossiq half. What belongs to the workflow engine is named in D-7 and in the
PR body rather than faked with a second table.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `caseType.splitDivides`
  (`documents`, `parties`), empty meaning everything this app can divide
  (D-2). `caseType` at 1.15.0.
- [x] 1.2 `lib/Service/CaseSplitService.php` moves the chosen rows by
  repointing their `case`, refuses a row that is not on the case being split,
  and reports what moved and what did not (D-1, D-3).
  - `tests/Unit/Service/CaseSplitServiceTest.php`
- [x] 1.3 The typed relation on both cases, reusing the `vervolg` nature
  `CaseRelationService` already stores. Both directions written here until
  openregister's `relation-types-with-inverses` owns the inverse.
- [x] 1.4 A forbidden division is refused with a status and a sentence naming
  what was refused (D-2).
- [x] 2.1 The `incident` schema: the event date, the recording moment, the
  reporter, the description, the owner, the state and the outcome (D-4, D-6).
  - `tests/Unit/Service/IncidentServiceTest.php`
- [x] 2.2 `GET /api/case/{caseId}/incidents` answers them in EVENT-date order,
  with the open count. Sorted in the service rather than asked of the store,
  because the order is the requirement.
- [x] 2.3 `POST .../incidents/{incidentId}/hand-over` writes the incident's own
  assignee and touches nothing on the case (D-5).
- [ ] 3.1 Open incidents on the WORK LIST. Not built here: the list reads
  cases and an incident is not one, so counting them there is the queue
  reader's. `IncidentService::openCountOn` is the count it would read.
- [x] 4.1 Dutch and English strings for the refusals and the two failures.
- [x] 4.2 `tests/e2e/splitting-a-case-and-its-incidents.spec.ts`; `openspec
  validate splitting-a-case-and-its-incidents --strict`.
