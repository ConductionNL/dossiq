# Tasks: splitting-a-case-and-its-incidents

Tier: V1. Kind: code. Size M. Rows 2.35 and 2.45.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: declare per case type what a
  split may divide (documents, parties, tasks), with a default that allows
  all three (D-2).
  - `@spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md`
- [ ] 1.2 The split action on `#CaseDetail`: pick the items, open the second
  case, move what was picked, leave a reference in the original (D-1, D-3).
  - `tests/unit/Service/CaseSplitServiceTest.php`
- [ ] 1.3 Write the typed relation on both cases, reusing the relation
  `CaseRelationService` already stores; name the openregister inverse slug
  once that lane opens it.
  - `tests/unit/Service/CaseSplitRelationTest.php`
- [ ] 1.4 Refuse a split of what the case type does not allow, naming the
  rule that refused (D-2).
- [ ] 2.1 `lib/Settings/dossiq_register.json`: the `incident` schema with
  the event date, the recording moment, the reporter, the description, the
  owner, the state and the outcome (D-4, D-6).
  - `tests/unit/Service/IncidentServiceTest.php`
- [ ] 2.2 The incidents tab on the case, in event-date order, showing the
  recording delay where the two differ (D-6).
  - `tests/vitest/caseIncidentsTab.spec.js`
- [ ] 2.3 The incident hand-off: its own assignee, changed without touching
  the case's owner (D-5).
  - `tests/unit/Service/IncidentHandoverTest.php`
- [ ] 3.1 Open incidents as a countable and filterable fact on the work
  list.
- [ ] 4.1 Dutch and English strings for the split dialog, the refusal, the
  incident form and the incident states.
- [ ] 4.2 `tests/e2e/splitting-a-case-and-its-incidents.spec.ts`: split with
  a chosen division, a refused split, three incidents with two owners;
  `openspec validate splitting-a-case-and-its-incidents --type change --strict`.
