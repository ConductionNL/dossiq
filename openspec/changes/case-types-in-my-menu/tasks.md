# Tasks: case-types-in-my-menu

Kind: code. Board canon: DqZijbalk and DqPersoonlijkeInstellingen
(design-system#152).

## 1. Backend

- [x] 1.1 `lib/Service/MenuCaseTypesService.php`: visible current case types,
  the user's chosen list (IConfig user value `menu_case_types`, Woo default,
  saved empty list kept), save with cleaning (visible only, no duplicates, at
  most 30).
  - `tests/Unit/Service/MenuCaseTypesServiceTest.php`
- [x] 1.2 `lib/Controller/MenuCaseTypesController.php` + routes
  `GET/PUT /api/menu-case-types`, `#[NoAdminRequired]`, current user only.
  - `tests/Unit/Controller/MenuCaseTypesControllerTest.php`
- [x] 1.3 `lib/Controller/ManifestController.php`: the delta is the caption
  "My case types" (order 30, `href` to the Dossiq personal settings section)
  plus one entry per chosen case type at 31 upward; no `CasesGroup` children.
  - `tests/Unit/Controller/ManifestControllerTest.php`

## 2. Frontend

- [x] 2.1 `src/menu-layout.simple.json`: drop `WooRequestsMenu`, add
  `MyCaseTypesCaption` at 30, move Relations, Contacts, Organisations to
  80/82/84.
  - `tests/vitest/structureProfile.spec.js`
- [x] 2.2 `src/views/settings/MenuCaseTypesSettings.vue` +
  `src/services/menuCaseTypesApi.js`, mounted from `src/personalSettings.js`
  into a new mount point in `templates/settings/personal.php`.
  - `tests/vitest/menuCaseTypesSettings.spec.js`
  - `tests/e2e/case-types-in-my-menu.spec.ts`
- [x] 2.3 l10n: en and nl strings, `npm run l10n:build`.
  - `npm run test:l10n`

## 3. Specs

- [x] 3.1 Amend `simple-structure-profile`'s nine-entry and Woo requests
  scenarios to the board.

## 4. Follow-ups (not in this change)

- [ ] 4.1 nextcloud-vue: CnAppNav draws a caption's `href` as a pencil link in
  `NcAppNavigationCaption`'s actions slot. Until then the caption's `href` is
  inert and the section is reached from the user menu.
- [ ] 4.2 The open-case count beside each case type on the board.
