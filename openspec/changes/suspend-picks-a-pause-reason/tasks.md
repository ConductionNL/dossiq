# Tasks: suspend-picks-a-pause-reason

## 1. Server

- [x] 1.1 `/lifecycle` answers the case type's pause reasons; suspend takes `pauseReason`, validates it against the declared reasons and passes it to the pause
  - Spec ref: specs/termijn-pause-extension/spec.md
  - Files: lib/Service/CaseLifecycleService.php, lib/Controller/CaseLifecycleController.php
  - Test: tests/Unit/Service/CaseLifecycleSuspendPauseReasonTest.php, tests/Unit/Controller/CaseLifecycleControllerTest.php

## 2. Case page

- [x] 2.1 The Opschorten form's reason picker with Other, the legal basis under the days, and the key in the post
  - Spec ref: specs/termijn-pause-extension/spec.md
  - Files: src/utils/pauseReasonChoice.js, src/components/case/CaseActFields.vue, src/dialogs/CaseLifecycleMenuDialog.vue, src/utils/caseLifecycleHelpers.js, l10n
  - Test: tests/vitest/suspendPicksAPauseReason.spec.js

## 3. Shipped pause reasons

- [x] 3.1 The Awb 4:5 and 4:15 lid 1 sub b grounds on the vth Omgevingsvergunning Bouwactiviteit and the subsidie case type, packages to 1.1.0
  - Files: lib/Settings/packages/vth/case-type.json, lib/Settings/packages/vth/package.json, lib/Settings/packages/subsidie/case-type.json, lib/Settings/packages/subsidie/package.json
  - Test: tests/Unit/Settings/ShippedPackagesAreValidTest.php, tests/Unit/Settings/PackageExampleRowsMatchTheirSchemasTest.php

## 4. Open

- [x] 4.1 A default number of days per pause reason (case type schema), then prefill Dagen
  - Spec ref: specs/termijn-pause-extension/spec.md
  - Files: lib/Settings/register.d/99-case-type-pause-reason-days.json (caseType 1.32.0), lib/Service/Pause/PauseReason.php, lib/Service/DeadlinePauseService.php, src/utils/caseActInputs.js (nextcloud-vue `prefillFrom`)
  - Test: tests/Unit/Service/Pause/PauseReasonDefaultDaysTest.php, tests/vitest/suspendPicksAPauseReason.spec.js
