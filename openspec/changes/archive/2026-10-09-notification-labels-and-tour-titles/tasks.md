# Tasks: notification-labels-and-tour-titles

## 1. Notification rules have labels
- [x] 1.1 `src/utils/notificationLabels.js` with a label per declared rule, en + nl; `src/App.vue` passes it to CnAppRoot.
  - test: `tests/vitest/notificationLabels.spec.js` (fails today: module missing)

## 2. The footer shows the installed version
- [x] 2.1 `DashboardController::renderIndex()` provides `version` from `installed_version`.
  - test: `tests/Unit/Controller/DashboardControllerInstalledVersionTest.php` (fails today: 2 of 2)
- [x] 2.2 `webpack.config.js` defines `appVersion` through `appVersionDefinition()` in `scripts/appVersion.js`.
  - test: `tests/vitest/appVersion.spec.js` (fails today: 3 cases)

## 3. Every tour step has a title
- [x] 3.1 Titles for `go-cases`, `create-case` and `go-tasks` in `src/manifest.json`, en + nl.
  - test: `tests/vitest/walkthroughStepTitles.spec.js` (fails today: steps 2, 3 and 6)
