# Tasks: simple-case-page

- [x] 1.1 `statusRole` on the case: property and calculation in
  `lib/Settings/dossiq_register.json`, register version 0.20.15, en and nl
  strings (design D-1).
- [x] 1.2 `@conduction/nextcloud-vue` `^2.60.0`.
- [x] 2.1 `configPatch` in `src/utils/structureProfile.js`.
- [x] 2.2 The `CaseDetail` overlay in `src/menu-layout.simple.json`: stage,
  buttons, checklists, quick actions, groups, pill, side column, tabs
  (design D-2 to D-5).
- [x] 3.1 `tests/vitest/simpleCasePage.spec.js`.
- [x] 3.2 `lib/Repair/BackfillCaseStatusRole.php`, registered post-migration,
  with `BackfillCaseStatusRoleTest`.
- [x] 3.3 After the live check: stage buttons that cannot hide, the three tiles
  out of the grid, the number as a pill, the deadline in the side column.
- [ ] 4.1 (live pass, decision 139) Live check by the coordinator, after the import and
  `occ openregister:rematerialise-calculations`.
