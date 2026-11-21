# Tasks: vth-package

- [x] 1 Ship `lib/Settings/packages/vth/` (`install: auto`): case types, statuses, roles, fields, document types, four published flows, three inspection checklists.
  - validated by `tests/Unit/Settings` (payload, category, action vocabulary and flow status sweeps)
- [x] 2 Delete `VthSeedDataRepairStep`, `SeedVthWorkflowTemplates`, `Repair/Vth/*`, `vth_seed_data.json`, `seed/vth-workflow-templates/`, their unit tests and their `info.xml` steps.
  - validated by `tests/Unit/Repair/RepairStepRegistrationTest.php`
- [x] 3 Retarget the sweeps and vitest files that read the seed file to the package. Add `PackageFlowStatusReferencesTest`.
- [x] 3.1 Delete `VTHTemplateService`, `VTHTemplateController`, their two routes and their unit tests.
- [x] 4 Newman collection `tests/integration/packages/vth.postman_collection.json`, run by the packages workflow on a clean instance.
- [x] 5 Playwright journey `tests/e2e/packages/vth.spec.ts`.
- [x] 6 Ship the six flow drafts of `lib/Settings/vth-templates/` as draft flows of the package and delete the directory (decision 208).
  - validated by `tests/Unit/Settings/PackageDraftFlowsTest.php`, `tests/Unit/Settings/WorkflowGuardConformanceTest.php` and the Newman collection
- [x] 6.1 `MigrateWorkflowDefinitions` writes the basis flow for a case type that holds only drafts.
  - validated by `tests/Unit/Repair/MigrateWorkflowDefinitionsLabelsTest.php`
- [x] 6.2 The journey reads the six drafts as drafts and checks that no case follows one.
