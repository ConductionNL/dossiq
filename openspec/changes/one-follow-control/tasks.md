# Tasks: one-follow-control

- [x] 1. `CaseFollowStrip`: the notifications switch, eye icon for Follow; `watcherApi.setNotify()` and `notifiesOf()`. Test: `tests/vitest/caseFollowers.spec.js`.
- [x] 2. Remove `CaseFavouriteStrip`, `favouriteApi.js`, `caseFavourite.js`, the `case-favourite` widget type and their test; `toggleCaseFollow` row action. Tests: `tests/vitest/caseFollowers.spec.js`, `tests/vitest/caseActionsMenu.spec.js`.
- [x] 3. Manifest: no Favourites chip, Following chip, Cases you follow tile on the Dashboard, Follow row actions. Tests: `tests/vitest/caseListLenses.spec.js`, `tests/vitest/caseFollowers.spec.js`.
- [x] 4. Case schema: `assignee` carries `x-openregister-role: assignee`; register 0.20.23, case 1.40.0.
- [x] 5. e2e: `tests/e2e/case-number-and-favourites.spec.ts` drives the follow instead of the star.
- [x] 6. l10n en and nl.
