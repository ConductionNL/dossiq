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

## 3. Open

- [ ] 3.1 A default number of days per pause reason (case type schema), then prefill Dagen
