# Tasks: lhs-packages

- [x] 1 Ship `lib/Settings/packages/lhs-matrix/` (`install: auto`): the default matrix, matched on `active`, and the 16 grid cells, matched on row and column.
  - validated by `tests/Unit/Settings/ShippedPackagesAreValidTest.php`, `tests/Unit/Settings/LhsMatrixGridTest.php` and `tests/Unit/Settings/LhsAxisVocabularyTest.php`
- [x] 2 Ship `lib/Settings/packages/lhs-intervention/` (`install: auto`): the table `dossiq.lhs-intervention` in OpenRegister's `decision-tables` register, matched on `key`.
  - validated by `tests/Unit/Settings/ShippedPackagesAreValidTest.php`
- [x] 3 Delete `SeedLhsMatrix`, `SeedVthMatrixCells`, their `info.xml` steps and both seed files. Keep `RealignLhsActorTypeVocabulary`.
  - validated by `tests/Unit/Repair/RepairStepRegistrationTest.php`
- [x] 4 Newman collections `tests/integration/packages/lhs-matrix.postman_collection.json` and `lhs-intervention.postman_collection.json`, run by the packages workflow on a clean instance.
- [x] 5 Playwright journeys `tests/e2e/packages/lhs-matrix.spec.ts` and `lhs-intervention.spec.ts`.
- [x] 6 Generate the intervention table from the 16 shipped grid cells (decision 213), with the cell's explanation as a second output, so a lookup that reads the table answers what the grid answered.
  - validated by `tests/Unit/Settings/LhsInterventionTableMatchesGridTest.php` (all 16 positions through OpenRegister's evaluator, and three controls: one changed cell, one changed rule, one missing rule) and by the read "the table answers what each grid cell says" in the Newman collection
