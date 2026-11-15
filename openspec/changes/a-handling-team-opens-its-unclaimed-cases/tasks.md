# Tasks: a-handling-team-opens-its-unclaimed-cases

- [x] 1.1 `CaseAccessGuard::hasCaseReadAccess()` lets the case type's handling teams read
  - Files: lib/Service/CaseAccessGuard.php
  - Test: tests/Unit/Service/CaseAccessGuardReadAccessTest.php
- [x] 1.2 The acts read is behind the read guard; a reader who is not the handler sees every act refused
  - Files: lib/Controller/CaseActsController.php, l10n/
  - Test: tests/Unit/Controller/CaseActsControllerContractTest.php
- [x] 2.1 `announceCasesChanged()` reaches the index page and the dashboard lists; every bare `dossiq:cases-changed` uses it
  - Files: src/utils/casesChanged.js, src/utils/caseClaim.js, src/utils/caseBulkActions.js, src/utils/caseUnread.js, src/utils/caseFollow.js, src/components/case/CaseUnreadPanel.vue, src/components/case/CaseAttentionPanel.vue
  - Test: tests/vitest/caseClaimAction.spec.js
- [ ] 3.1 Live: a `behandelaars` member opens an unclaimed Woo request, claims it from the queue, and the queue drops it
