# Tasks: landing-views

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^2.67.0` (lockfile only;
  dependencies identical to 2.65.0).
- [x] 2.1 `src/manifest.json`: `MyWorkHome` declares `views` (`mine`, `team`),
  `defaultView: mine` and `viewsLabel`; its widgets move into `mine`; `team`
  copies the dashboard's team widgets; `userLayout` leaves the page.
- [x] 3.1 `src/menu-layout.simple.json`: the greeting and the design widgets move
  from the `Dashboard` overlay to the `MyWorkHome` overlay; the greeting options
  carry `view`; `configPatch` replaces only the `mine` view.
- [x] 3.2 The simple `Dashboard` overlay keeps only the Close out your day link.
- [x] 4.1 `tests/vitest/landingViews.spec.js` and `tests/vitest/helpers/pageViews.js`;
  the specs that read the landing page's widgets read them through the helper.
- [x] 4.2 One new string (`Nothing to show for your team yet`), en and nl.
- [x] 5.1 Live check by the coordinator: open `/apps/dossiq/` in both structures,
  switch to My team and back, reload with `?view=team`.
  Done 7 October 2026: simple structure (greeting draws the switch) and full
  structure (switch in the header), `?view=team` survives a reload, the stored
  choice opens next time, My work shows its widgets and My team the team queue,
  counts and stalled cases.
