# Tasks: r4-tour-menu-labels-and-settings-styles

## 1. Tour tasks name the real menu labels
- [x] 1.1 `src/manifest.json` tour tasks: "Click All cases in the menu", "Open Advanced, then Case types", "Open Advanced, then Flows"; `l10n/en.json`, `l10n/nl.json` and the built `.js` catalogues.
  - test: `tests/vitest/tourMenuLabels.spec.js` (fails on the old manifest: "Cases" is not the label of the Cases route in either structure)

## 2. Personal settings load the library stylesheet
- [x] 2.1 `src/personalSettings.js` and `src/emailSettings.js` import `@conduction/nextcloud-vue/css/index.css`; the scope picker in `NotificationRoutingSettings.vue` gets a minimum width.
  - test: `tests/vitest/entryLibraryCss.spec.js` (fails on the old entries)
  - live: Settings > Personal > Dossiq on :8099, the channel header stacks "Notifications" over "Bundle these"
