# Tasks: case-type-picker-offers-the-current-version

- [x] 1.1 `case.caseType` `x-relation-filter` adds `supersededBy: "IS NULL"`;
  `case` schema 1.40.0 to 1.42.0; digests regenerated.
  - `tests/vitest/newCaseCaseTypePicker.spec.js`
- [x] 2.1 The case-flow seed finds a stored case type by its slug.
  - `tests/Unit/Repair/CaseFlowSeedDataRepairStepTest.php::testACaseTypeStoredByAnEarlierRunIsFoundBySlug`
  - `tests/Unit/Repair/CaseFlowSeedIndexTest.php::testACaseTypeIsLookedUpBySlugInTheMetadata`
- [x] 3.1 Decide which copy of the duplicated seeded case type an existing
  install keeps, and where the other copy's cases go. Decided 22 Oct: the
  picker offers only the current version; an older one stays reachable from
  the case type page. The newest copy stays current, every older copy of the
  same slug and version is superseded by it, and no case moves.
  - `lib/Repair/SupersedeDuplicateSeededCaseTypes.php` (post-migration)
  - `tests/Unit/Repair/SupersedeDuplicateSeededCaseTypesTest.php`
