# Tasks: one-follow-control-in-the-header

- [x] 1.1 Remove `CaseFollowStrip` from `CaseBannerStack`; delete the component and the
  `case-follow` registry type.
  - `tests/vitest/caseFollowers.spec.js` (leaves the one Follow control to the header)
  - `tests/vitest/caseActionsMenu.spec.js` (archived strip still leads the stack)
- [x] 1.2 Delete `setNotify`, `notifiesOf` and `followerCountOf` from `watcherApi.js`; the
  two verbs are asserted on `setFollowing()`.
  - `tests/vitest/caseFollowers.spec.js` (the two verbs)
- [x] 1.3 e2e drives the header toggle and asserts there is one.
  - `tests/e2e/case-followers.spec.ts`, `tests/e2e/case-number-and-favourites.spec.ts`
