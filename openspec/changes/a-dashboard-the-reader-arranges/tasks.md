# Tasks: a-dashboard-the-reader-arranges

Tier: V1. Kind: code. Rows 10.1 and part of 10.10. Consumer half of
nextcloud-vue `dashboard-layout-per-user` (#1209, merged onto
`parity/round2`).

## 1. The key

- [x] 1.1 Confirm the installed `@conduction/nextcloud-vue` carries
      `mergeUserLayout`, `listUserAddableWidgetTypes` and
      `userWidgetPresets` before declaring the key. A manifest key the
      pinned library does not read renders the page exactly as before and
      says nothing, which is indistinguishable from the feature working for
      a user who never dragged a widget.
      - MEASURED 2026-09-18, and the answer is NO. The installed 3.2.0 and the
        newest published 3.3.0 (released 2026-09-18 04:41) carry none of the
        three: no `mergeUserLayout`, no `dashboardLayouts` store plugin, no
        `listUserAddableWidgetTypes`, no `userWidgetPresets`. All of it is on
        nextcloud-vue `parity/round2` (#1209) and in no release. The keys are
        declared anyway, because a page carrying them under a library that
        does not read them renders exactly as it does today and costs nothing,
        and because the alternative is a change that cannot land until an
        unrelated release does. The feature switches on with that release;
        until then the e2e spec is red and says why in its header.
- [x] 1.2 `src/manifest.json`: `config.userLayout: true` on `Dashboard`,
      `MyWorkHome`, `Doorlooptijd`, `ProcessMiningDashboard` and
      `TermijnDashboard`.
      - vitest: all five carry the key; no other page does

## 2. The presets

- [x] 2.1 `src/manifest.json`: `config.userWidgets` on `Dashboard`, two
      `object-list` presets over `case`, "Cases you follow" and "Your
      team's queue", each carrying its own filter.
      - The second preset is the SHARED QUEUE, not `assignedGroup`. No token
        resolves "my groups": `resolveFilterTokens` knows @me, @now, @today,
        @today±Nd, @monthStart, @quarterStart and @yearStart, the manifest
        schema's `sentinelFilterToken` pattern enumerates exactly those, and
        OpenRegister's reserved-parameter list carries no group lens. A preset
        on an unresolved token sends the literal string and is a permanently
        empty card. The group-scoped variant is nextcloud-vue's half.
      - vitest: each preset resolves to a filter the case list accepts, and
        neither asks the reader for a register or a schema

## 3. Tests

- [x] 3.1 `tests/e2e/a-dashboard-the-reader-arranges.spec.ts`: move a widget
      as one user, reload, see it where it was left; sign in as a second
      user and see the manifest arrangement.
- [x] 3.2 The same spec: add a preset widget from the modal and see it on
      the grid.

## 4. Waiting

- [ ] 4.1 Row 10.10 stays open here. Place the saved-view widget once
      ConductionNL/nextcloud-vue `a-saved-view-drives-a-widget` ships one.
      This app already declares `savedViewPlaces` on two index pages, so the
      views exist; nothing can render one on a dashboard yet.

## 5. The release landed, and the key was not enough, 2026-09-20

- [x] 5.1 Task 1.1's answer has changed. `@conduction/nextcloud-vue` 3.4.0
      was published on 2026-09-19 and this repo's lockfile moved to it the
      same morning. Read out of the installed package rather than assumed:
      `mergeUserLayout`, the `dashboardLayouts` store plugin,
      `listUserAddableWidgetTypes` and `userWidgetPresets` are all there,
      and `userWidgetPresets` returns this page's two presets when handed
      its real config. So these keys are read by something now.
- [x] 5.2 🔴 `userLayout: true` ON ITS OWN STORES NOTHING.
      `CnDashboardPage.loadUserLayout()` and `saveUserLayout()` both open
      with `if (!this.userLayout || !this.appId)`, and the manifest renderer
      binds the page's `config` and no app id of its own, so all five
      dashboards made no layout request and wrote no record. A reader who
      never drags a widget cannot tell that apart from the feature working,
      which is why it survived a merge.
      - The five pages now declare `appId: "dossiq"`, and `pageId` beside
        it: left out, the record is keyed on a slug of the page TITLE, so
        editing a heading orphans everybody's arrangement in silence.
      - Mutation checked: taking `appId` off `Doorlooptijd` reddens
        "expected undefined to be 'dossiq'".
