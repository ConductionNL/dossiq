# Tasks: case-type-picker-offers-the-current-version

- [x] 1.1 `case.caseType` `x-relation-filter` adds `supersededBy: "IS NULL"`;
  `case` schema 1.40.0 to 1.42.0; digests regenerated.
  - `tests/vitest/newCaseCaseTypePicker.spec.js`
- [x] 2.1 The case-flow seed finds a stored case type by its slug.
  - `tests/Unit/Repair/CaseFlowSeedDataRepairStepTest.php::testACaseTypeStoredByAnEarlierRunIsFoundBySlug`
  - `tests/Unit/Repair/CaseFlowSeedIndexTest.php::testACaseTypeIsLookedUpBySlugInTheMetadata`
- [ ] 3.1 Decide which copy of the duplicated seeded case type an existing
  install keeps, and where the other copy's cases go.
