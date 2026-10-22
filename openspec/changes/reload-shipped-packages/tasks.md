# Tasks

- [x] `PackageInstaller::reloadInstalled()`: install every package installed here or marked `auto`.
- [x] `PackageController::reload()` on `POST /api/packages/reload`, `AuthorizedAdminSetting`, user check before the installer; 500 when a package failed.
- [x] Case type list (simple profile): Actions menu entry "Reload templates", an `api-call` header action with a confirmation.
- [x] Tests: `testAReloadInstallsWhatIsInstalledOrAutomaticAndNothingElse`, `testReloadIsAdminOnlyAndRefusesWithoutAUser`, `testReloadAnswersEachOutcomeAnd500WhenOneFailed` (all fail before the change).
