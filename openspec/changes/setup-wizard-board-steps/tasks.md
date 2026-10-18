# Tasks: setup-wizard-board-steps

- [x] 1. Organisation step: `ActiveOrganisationProfile` reads and writes the active organisation's name and logo; `SetupController::saveConfig` routes the two fields there and never into app config
  - Files: lib/Service/Setup/ActiveOrganisationProfile.php, lib/Controller/SetupController.php, src/manifest.json
  - Test: tests/Unit/Service/Setup/ActiveOrganisationProfileTest.php, tests/Unit/Controller/SetupControllerStatusTest.php
- [x] 2. House style step: `info` with `requires: ["thematiq"]`, reported done
  - Files: src/manifest.json, lib/Controller/SetupController.php
- [x] 3. Case types step: `CaseTypePackageChoice` offers the on-request packages and installs one per card (`install-case-types`); status carries `caseTypePackages`
  - Files: lib/Service/Setup/CaseTypePackageChoice.php, lib/Controller/SetupController.php, src/manifest.json
  - Test: tests/Unit/Service/Setup/CaseTypePackageChoiceTest.php, tests/Unit/Controller/SetupControllerStatusTest.php
- [x] 4. Board order and l10n (en, nl)
