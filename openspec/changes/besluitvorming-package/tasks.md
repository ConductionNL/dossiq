# Tasks: besluitvorming-package

- [x] 1.1 Extend `scripts/packages/seed-to-package.py` to the bundle shape (property definitions, document types, result types, `initialStatusName`, one case type per file, ids derived from the identifier) and convert the three bundles into `lib/Settings/packages/besluitvorming/`, `install: on-request`.
- [x] 1.2 Generic package API: `GET /api/packages`, `POST /api/packages/{id}/install`.
  - `tests/Unit/Controller/PackageControllerTest.php`
- [x] 1.3 Sweep every package case type for a category.
  - `tests/Unit/Settings/SeededCaseTypeCategoriesTest.php::testEveryPackageCaseTypeHasACategory`
- [x] 1.4 Newman collection `tests/integration/packages/besluitvorming.postman_collection.json`, run by the packages workflow on a clean instance.
- [x] 1.5 Playwright journey `tests/e2e/packages/besluitvorming.spec.ts`.
- [x] 1.6 Delete `BesluitvormingTemplateService`, `Besluitvorming/*`, `SeedBesluitvormingTemplates`, `BesluitvormingController` and its route, the `bvw-*.json` bundles and their unit tests.
