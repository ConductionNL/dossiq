# Tasks: task-forms-keep-a-draft

- [x] 0 nextcloud-vue: `CnIndexPage` and `CnDetailPage` forward `draftAppId`, `allowDraft` and `draftField` to the forms they open (nextcloud-vue#2021, change `pages-forward-form-drafts`). Before it, no page config reached those `CnFormDialog` props (decision 156: built in the library, not parked).
- [x] 1 In `src/manifest.json`, page `TaskDetail`: `draftAppId: "dossiq"` with a `_draftNote`. The task's Edit form is the engine's form; an engine task has no draft marker, so it keeps the typed draft but offers no Save draft.
- [x] 2 In `src/manifest.json`, page `CaseDetail`: `draftAppId`, `allowDraft`, `draftField: "isDraft"` on the case edit form. Save draft shows only while the case is still a draft, so a case in use is never taken out of use. The new-case form on page `Cases` offers Save draft (a draft is the case in status draft, decision 180).
- [ ] 3 Bump `@conduction/nextcloud-vue` in `package.json` to the release that ships `pages-forward-form-drafts` once it is published. Until then the keys are ignored.
- [ ] 4 `tests/e2e/task-form-draft.spec.ts`: restore after close, save as draft with required fields empty. Tag both scenarios of REQ-TM-DRAFT-01.
- [x] 5 `tests/vitest/manifestDrafts.spec.js`: the three pages carry `draftAppId`; the case pages carry `allowDraft` on `isDraft`.
- [ ] 6 Live pass (decision 139): fill half a task form on :8080, reload, see the values offered back.
- [ ] 7 `openspec/parity/capabilities.json` row 3.14: `built.state` built, evidence the manifest paths, `reachedOn` TaskDetail.
