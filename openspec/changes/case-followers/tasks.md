# Tasks: case-followers

Tier: V1. Kind: config. Row 13.18.

The wait is over: openregister `object-watchers` is on `development`. It
ships `PUT|DELETE .../watch`, `GET .../watchers`, the `_watching` lens,
`@self.watching` and `@self.watcherCount`, and the `{"watchers": true}`
recipient kind. dossiq declares and renders; no PHP here.

- [x] 1.1 `src/manifest.json` `#CaseDetail`: a Follow strip beside the star
  (D-1), over `PUT|DELETE /api/objects/{r}/{s}/{id}/watch`.
  - `src/components/case/CaseFollowStrip.vue`,
    `src/services/watcherApi.js`, `src/registry.js`, `src/icons.js`
  - `tests/vitest/caseFollowers.spec.js`
  - `@spec openspec/changes/case-followers/specs/case-management/spec.md`
- [x] 1.2 `#Cases` lens Followed; `#MyWorkHome` tile Cases you follow, both
  over `_watching`.
  - `tests/vitest/caseListLenses.spec.js`, `tests/vitest/caseFollowers.spec.js`
- [x] 1.3 People tab: Followers section (D-2).
  - `src/components/case/CaseFollowersPanel.vue`
- [x] 1.4 `lib/Settings/dossiq_register.json`: the case schema addresses its
  watchers on a `transition` trigger and on the escalation (D-3).
- [x] 2.1 `tests/e2e/case-followers.spec.ts`; `openspec validate
  case-followers --strict`.
