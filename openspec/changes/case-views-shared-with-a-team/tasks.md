# Tasks: case-views-shared-with-a-team

- [ ] 1 On :8080 with `@conduction/nextcloud-vue` ^2.164.0: open Cases, save a view, record whether the share picker shows and which groups it lists. Write the finding into this task.
- [ ] 2 If the picker lists every Nextcloud group: give the three `allowSavedViews` pages in `src/manifest.json` (cases, tasks, queue) a share-group source limited to the reader's organisation-role groups (the same source `workload-of-the-readers-teams` reads); if the library has no prop for that, build it in nextcloud-vue first (decision 156) and bump.
- [ ] 3 `tests/e2e/case-views-shared.spec.ts`: share read-only, member sees it, member cannot overwrite; picker shows only own teams.
- [ ] 4 Example set: one shared view "Te beoordelen deze week" for Team Vergunningen in `lib/Settings/example-sets/` next to `example-set-board-saved-views`.
- [ ] 5 `openspec/parity/capabilities.json` row 9.4: built, evidence the manifest pages and the example view; board DqZaken.
