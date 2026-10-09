# Tasks: my-teams-queue

Kind: code. Based on `feat/one-team-model` (#3533).

## 1. Manifest

- [x] 1.1 `your-teams-queue` (Dashboard `userWidgets`) and the My team view's copy filter `assignedGroup` on `@myGroups`; new empty text and prompt, nl and en.
  - `tests/vitest/dashboardUserLayout.spec.js`, `tests/vitest/landingViews.spec.js`
- [x] 1.2 Team column on the Cases index: `widget: group`.
  - `tests/vitest/casePartiesWidget.spec.js`
- [x] 1.3 `_userWidgetsNote`, `_viewsNote`, `_teamColumnNote`.

## 2. After the nextcloud-vue release

- [ ] 2.1 Bump `@conduction/nextcloud-vue` to the release that carries nextcloud-vue #1424 and re-vendor `tests/schemas/app-manifest-v2.schema.json` from it.
