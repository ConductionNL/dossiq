# Tasks: case-type-handling-teams

Kind: code. Stacks on `case-types-in-my-menu` (dossiq#3530). Board canon:
`DqPersoonlijkeInstellingen` (design-system#152) and `DqZaaktype`
(design-system PR of this change).

## 1. Backend

- [x] 1.1 `lib/Settings/register.d/48-starter-content.json`: `handling.teams`,
  an array of Nextcloud group ids.
- [x] 1.2 `lib/Service/CaseType/CaseTypeHandling.php`: `teams()` and `teams` in
  `READ_SWITCHES`.
  - `tests/Unit/Service/CaseTypeHandlingSwitchesTest.php`
- [x] 1.3 `lib/Service/MenuCaseTypesService.php`: `offeredCaseTypes()` (team
  rule with the access fallback); the controllers read it instead of
  `visibleCaseTypes()`.
  - `tests/Unit/Service/MenuCaseTypesServiceTest.php`
  - `tests/Unit/Controller/MenuCaseTypesControllerTest.php`
  - `tests/Unit/Controller/ManifestControllerTest.php`

## 2. Frontend

- [x] 2.1 `src/views/settings/tabs/GeneralTab.vue`: "Handling teams" field;
  `src/services/nextcloudGroupsApi.js`.
  - `tests/vitest/generalTabHandlingTeams.spec.js`
- [x] 2.2 Hint under the picker back to the board text, nl and en.
- [x] 2.3 l10n: en and nl strings.
  - `npm run test:l10n`

## 3. Specs and board

- [x] 3.1 Amend `case-types-in-my-menu` REQ-CTN-004, proposal and design.
- [x] 3.2 design-system: `DqZaaktype` details card gains "Behandelende teams".
