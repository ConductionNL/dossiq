# Tasks: a-dashboard-the-reader-arranges

Tier: V1. Kind: code. Rows 10.1 and part of 10.10. Consumer half of
nextcloud-vue `dashboard-layout-per-user` (#1209, merged onto
`parity/round2`).

## 1. The key

- [ ] 1.1 Confirm the installed `@conduction/nextcloud-vue` carries
      `mergeUserLayout`, `listUserAddableWidgetTypes` and
      `userWidgetPresets` before declaring the key. A manifest key the
      pinned library does not read renders the page exactly as before and
      says nothing, which is indistinguishable from the feature working for
      a user who never dragged a widget.
- [ ] 1.2 `src/manifest.json`: `config.userLayout: true` on `Dashboard`,
      `MyWorkHome`, `Doorlooptijd`, `ProcessMiningDashboard` and
      `TermijnDashboard`.
      - vitest: all five carry the key; no other page does

## 2. The presets

- [ ] 2.1 `src/manifest.json`: `config.userWidgets` on `Dashboard`, two
      `object-list` presets over `case`, "Cases you follow" and "Your
      team's cases", each carrying its own filter.
      - vitest: each preset resolves to a filter the case list accepts, and
        neither asks the reader for a register or a schema

## 3. Tests

- [ ] 3.1 `tests/e2e/a-dashboard-the-reader-arranges.spec.ts`: move a widget
      as one user, reload, see it where it was left; sign in as a second
      user and see the manifest arrangement.
- [ ] 3.2 The same spec: add a preset widget from the modal and see it on
      the grid.

## 4. Waiting

- [ ] 4.1 Row 10.10 stays open here. Place the saved-view widget once
      ConductionNL/nextcloud-vue `a-saved-view-drives-a-widget` ships one.
      This app already declares `savedViewPlaces` on two index pages, so the
      views exist; nothing can render one on a dashboard yet.
