# Tasks: family-plan-by-case

- [x] 1.1 Tests: `tests/Unit/Controller/SociaalDomeinPlanOfCaseTest.php` (3, red: no planOfCase) and `tests/vitest/familyPlanByCase.spec.js` (red: endpoints on @object.gezinsplanId).
- [x] 1.2 planOfCase + route; the plan card's endpoints; De melding plan row.
- [x] 1.3 Live: DqSociaalDomein on :8080 after the jeugdwet package 1.10.0 example plan is installed.
  - Live 25 Nov 2026 (live-dossiq-1; NC 34 + MariaDB, OpenRegister main, dossiq development, jeugdwet 1.16.0 with example data, Zuiddrecht set): as Pieter (with dossiq#6975), `GET /api/cases/e0b306d2-…/family-plan` answers 200 with the example plan fc4316d9 (draft, prepared by fatma.kaya, review date set), so the plan card reads the plan of its case. Two gaps for the household card beside it, not this change's: (1) `HouseholdCases::householdBsns()` still finds the plan through `case.gezinsplanId`, which the example case does not carry, not through the plan's `caseId` that planOfCase uses; (2) the example plan has no `gezinsleden`, so even through the plan the household has no BSNs and the card lists no rows.
