# Tasks: task-forms-keep-a-draft

- [x] 0 nextcloud-vue: `CnIndexPage` and `CnDetailPage` forward `draftAppId`, `allowDraft` and `draftField` to the forms they open (nextcloud-vue#2021, change `pages-forward-form-drafts`). Before it, no page config reached those `CnFormDialog` props (decision 156: built in the library, not parked).
- [x] 1 In `src/manifest.json`, page `TaskDetail`: on the form dialog that edits the task's form fields, set `draftField` (the object's draft marker, per nextcloud-vue `CnFormDialog` prop of that name) and `draftAppId: "dossiq"`. Add a `_draftNote` naming this change.
- [x] 2 In `src/manifest.json`, page `CaseDetail`: the same two props on the case edit form (`editTitle` dialog).
  - Plus `allowDraft: true`, so the form offers Save draft (REQ-TM-DRAFT-01 "Save as draft skips validation"); it shows only while the case is still a draft, so a case in use is never taken out of use. The new-case form on page `Cases` offers Save draft too (a draft is the case in status draft, decision 180).
- [x] 3 Check `@conduction/nextcloud-vue` in `package.json` is at or above the release that shipped `form-dialog-autosave`; bump only if lower.
- [x] 4 `tests/e2e/task-form-draft.spec.ts`: restore after close, save as draft with required fields empty. Tag both scenarios of REQ-TM-DRAFT-01. (Written as a non-admin handler from an empty storage state; lints and lists, not run here: it needs a live instance.)
- [x] 5 `tests/vitest/manifestDrafts.spec.js`: both pages carry `draftField` and `draftAppId`; the case pages also carry `allowDraft` on `isDraft`.
- [ ] 6 Live pass (decision 139): fill half a task form on :8080, reload, see the values offered back.
- [x] 7 `openspec/parity/capabilities.json` row 3.14: `built.state` built, evidence the two manifest paths, `reachedOn` TaskDetail.
