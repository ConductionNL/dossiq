# Tasks: r7-dossiq-filtered-title-board-config

## 1. Tab title of a list narrowed to one case type

- [x] 1.1 `pageTitleFor()` names a `?caseType=` route after the menu entry that opens it, else "Cases of one case type"; key added en + nl.
    - test: `tests/vitest/pageTitle.spec.js` "pageTitleFor on a list narrowed to one case type"
- [x] 1.2 `installPageTitles()` returns a re-title function; `main.js` calls it when the resolved menu changes.
    - test: `tests/vitest/pageTitle.spec.js` "re-titles the open list when the case type menu arrives", "follows the resolved menu in main.js"

## 2. Workflow board config

- [x] 2.1 WorkflowBoard page config declares neither `register` nor `schema`.
    - test: `tests/vitest/customPageTypesConfig.spec.js`
    - The WorkflowBoard page has since retired (the Cases board replaced it); the general check in that spec remains.

## 3. Verification

- [x] 3.1 New tests fail on the old code (8 of 17), pass on the new.
- [x] 3.2 Live on :8099: tab title on `/cases?caseType=<Woo-verzoek>`, no CnPageRenderer warning on `/workflow-board`.
