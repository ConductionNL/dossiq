# Tasks: case-views-shared-with-a-team

- [ ] 1 On :8080 with `@conduction/nextcloud-vue` ^2.164.0: open Cases, save a view, record whether the share picker shows and which groups it lists. Write the finding into this task.
  - Static reading (2026-11-13, `@conduction/nextcloud-vue` 2.180.0, not yet the live check): `CnSaveViewDialog` draws the share section with `CnSavedViewShareFields`, which searches Nextcloud's sharee API (`searchUserAndGroupSharees`) for people and groups within the instance's sharing settings. It has no prop to narrow the groups, so the picker offers every group the sharee API returns, not only the reader's organisation-role groups. Confirm live.
- [ ] 2 If the picker lists every Nextcloud group: give the three `allowSavedViews` pages in `src/manifest.json` (cases, tasks, queue) a share-group source limited to the reader's organisation-role groups (the same source `workload-of-the-readers-teams` reads); if the library has no prop for that, build it in nextcloud-vue first (decision 156) and bump.
  - Open: `CnSavedViewShareFields` 2.180.0 has no such prop (see task 1), so this waits on a nextcloud-vue change first.
- [ ] 3 `tests/e2e/case-views-shared.spec.ts`: share read-only, member sees it, member cannot overwrite; picker shows only own teams.
- [x] 4 Example set: one shared view "Te beoordelen deze week" for Team Vergunningen in `lib/Settings/example-sets/` next to `example-set-board-saved-views`.
  - `lib/Settings/example-sets/zuiddrecht.json` view `marleen-te-beoordelen-deze-week` (owner Marleen Postma, Teamleider in Vergunningen; shared read-only with `{{cast:vergunningen.gid}}`; the team's open cases due within seven days). Test: `tests/Unit/Settings/ZuiddrechtBoardSavedViewsTest.php::testMarleenSharesTheVergunningenWeekView`.
- [ ] 5 `openspec/parity/capabilities.json` row 9.4: built, evidence the manifest pages and the example view; board DqZaken.
