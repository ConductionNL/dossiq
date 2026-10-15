# Tasks: a-portal-request-names-its-requester

- [x] 1.1 The Woo intake writes the requester's name as `initiatorDisplayName`
  - Files: lib/Woo/WooRequestIntake.php
  - Test: tests/Unit/Woo/WooRequestIntakeTest.php
- [x] 1.2 The case page's Requester reads it through the `requesterName` formatter
  - Files: src/services/formatters.js, src/manifest.json
  - Test: tests/vitest/formatters.spec.js
- [ ] 1.3 Live: file a Woo request on the portal and see the name under Requester
