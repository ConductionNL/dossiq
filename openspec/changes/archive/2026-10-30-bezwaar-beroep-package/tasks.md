# Tasks: bezwaar-beroep-package

- [x] 1.1 Convert the parked seed into `lib/Settings/packages/bezwaar-beroep/` with `scripts/packages/seed-to-package.py` (fixed UUIDs, wildcards expanded, flows published).
- [x] 1.2 Declare the guarded fields as property definitions of their case type.
  - `tests/Unit/Settings/WorkflowGuardConformanceTest.php::testEveryRequiredFieldGuardNamesAFieldACaseCanAnswer`
  - `tests/Unit/Settings/WorkflowGuardConformanceTest.php::testThePackageFlowsAreSwept`
- [x] 1.3 The setup action `seed` installs the package.
  - `tests/Unit/Controller/SetupControllerStatusTest.php::testASeedThatInstalledThePackageRecordsTheStepAsDone`
  - `tests/Unit/Controller/SetupControllerStatusTest.php::testARefusedOrFailedSeedReportsTheInstallersOwnMessage`
- [x] 1.4 Newman collection `tests/integration/packages/bezwaar-beroep.postman_collection.json`, run by the packages workflow on a clean instance.
- [x] 1.5 Playwright journey `tests/e2e/packages/bezwaar-beroep.spec.ts`.
- [x] 1.6 Delete `SeedDataService`, `SeedBezwaarBeroepData`, `SeedBezwaarBeroepCommand`, `SeedBezwaarWorkflowDefinition`, `bezwaar_seed_data.json` and their unit tests.
