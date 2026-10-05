# Tasks: simple-structure-profile

Kind: code. Pilot for pipelinq, decidiq and learniq.

- [x] 1.1 `src/utils/structureProfile.js`: `resolveStructureProfile`,
  `applyPageOverlay`, `buildProfiledManifest` (design D-1, D-2).
- [x] 1.2 `src/menu-layout.simple.json`: the simple menu, the moves to settings
  and the page links (design D-3, D-4).
- [x] 1.3 `src/main.js` picks the layout file from initial state.
- [x] 1.4 `lib/Service/Settings/MenuStructure.php`, the `menu_structure` key in
  `ConfigKeys`, initial state from `DashboardController` and `AdminSettings`.
- [x] 1.5 Admin settings section "Menu structure"
  (`src/views/settings/tabs/MenuStructureTab.vue`,
  `src/services/menuStructureSetting.js`).
- [x] 1.6 l10n: the new strings in English and Dutch, and
  `tests/l10n/check-l10n.js` reads `src/menu-layout*.json`.
- [x] 2.1 The archive gate uses `op: "empty"` on the seven write actions
  (design D-5).
- [x] 3.1 `tests/vitest/structureProfile.spec.js`: both profiles built with the
  library's real `buildManifest`; the no-loss rule; the setting; the save.
- [x] 3.2 `tests/vitest/archivedGateEvaluates.spec.js`: the gate evaluated on a
  working case, a null marker and an archived case, with the old spelling as a
  control.
- [x] 3.3 `tests/Unit/Service/Settings/MenuStructureTest.php`.
- [x] 3.4 `tests/e2e/simple-structure-menu.spec.ts`, and
  `tests/e2e/ci-seed.sh` puts the CI instance on `full`.
- [ ] 4.1 Live check on a dev instance by the coordinator: the simple menu, the
  admin choice, and the seven write actions on a working case.
