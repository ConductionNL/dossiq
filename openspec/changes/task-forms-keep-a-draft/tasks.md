# Tasks: task-forms-keep-a-draft

- [ ] 1 In `src/manifest.json`, page `TaskDetail`: on the form dialog that edits the task's form fields, set `draftField` (the object's draft marker, per nextcloud-vue `CnFormDialog` prop of that name) and `draftAppId: "dossiq"`. Add a `_draftNote` naming this change.
- [ ] 2 In `src/manifest.json`, page `CaseDetail`: the same two props on the case edit form (`editTitle` dialog).
- [ ] 3 Check `@conduction/nextcloud-vue` in `package.json` is at or above the release that shipped `form-dialog-autosave`; bump only if lower.
- [ ] 4 `tests/e2e/task-form-draft.spec.ts`: restore after close, save as draft with required fields empty. Tag both scenarios of REQ-TM-DRAFT-01.
- [ ] 5 `tests/vitest/manifestDrafts.spec.js`: both pages carry `draftField` and `draftAppId`.
- [ ] 6 Live pass (decision 139): fill half a task form on :8080, reload, see the values offered back.
- [ ] 7 `openspec/parity/capabilities.json` row 3.14: `built.state` built, evidence the two manifest paths, `reachedOn` TaskDetail.
