# Tasks: menu-case-type-counts

Kind: code. Stacked on `case-type-handling-teams` (dossiq#3532), which stacks
on `case-types-in-my-menu` (dossiq#3530). Board canon: `DqPersoonlijkeInstellingen`.

## 1. Backend

- [x] 1.1 `MenuCaseTypesService::withOpenCaseCounts()` over `CaseType\OpenCaseCounts`: one terms facet on
  `caseType` over open cases, versions folded into the current one, `null`
  when unknown.
  - `tests/Unit/Service/MenuCaseTypesServiceTest.php`
- [x] 1.2 `MenuCaseTypesController::index()` answers `openCases` on chosen and
  available.
  - `tests/Unit/Controller/MenuCaseTypesControllerTest.php`

## 2. Frontend

- [x] 2.1 `MenuCaseTypesSettings.vue`: "{n} open cases" beside each row and
  option; nothing when the count is `null`.
  - `tests/vitest/menuCaseTypesSettings.spec.js`
- [x] 2.2 l10n en and nl.
