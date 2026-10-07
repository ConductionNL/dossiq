# Tasks: landing-view-layouts

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^2.69.0` (lockfile; dependencies
  identical to 2.68.0).
- [x] 2.1 `src/manifest.json`: `MyWorkHome` declares `userLayout: true`; the
  `_viewsNote` says why it came back.
- [x] 3.1 `tests/vitest/dashboardUserLayout.spec.js`: the landing page carries
  `userLayout` together with its views, `appId` and `pageId`;
  `declarationsReachTheLibrary.spec.js` lists it among the pages that ask for a
  user layout.
- [x] 3.2 Re-vendor `tests/schemas/app-manifest-v2.schema.json` from 2.69.0.
- [x] 3.3 `caseListPlace.spec.js` and `routePermissions.spec.js` run under jsdom:
  2.69.0's `useContextMenu` imports `rowActionItem`, whose chain loads
  `@nextcloud/auth`, which reads `window` at import.
- [ ] 4.1 Live check by the coordinator: arrange My team, reload, switch views,
  and confirm each view keeps its own arrangement.
