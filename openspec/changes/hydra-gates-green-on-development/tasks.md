# Tasks: hydra-gates-green-on-development

- [x] 1.1 `scope="col"` on the review batch table headers (gate-43)
  - Files: src/components/case/DocumentReviewPanel.vue
  - Test: `check_table_headers.py` over every markup file reports 0 findings
- [x] 1.2 Titles and descriptions on the relation-role and session properties (gate-51)
  - Files: lib/Settings/register.d/86-case-type-relation-roles.json, lib/Settings/register.d/87-case-type-sessions.json
  - Files: l10n/en.json, l10n/nl.json, l10n/en.js, l10n/nl.js
  - Test: `npm run check:schema-l10n` reports 0 uncovered against a baseline of 0; `npm run test:l10n` holds en/nl parity
- [x] 1.3 `CaseNumberReader` out of the term namespace (gate-119)
  - Files: lib/Service/CaseNumberReader.php, lib/Service/TermijnNotificationService.php, tests/Unit/Service/TermijnNotificationServiceTest.php
  - Test: tests/Unit/Service/TermijnNotificationServiceTest.php (9 tests, the real class over a fake object service)
- [ ] 1.4 Vendor manifest schema 2.96.0 in `ConductionNL/.github` so gate-22 and gate-53 accept `startOn`. Out of this repo; tracked by the pull request named in the PR body.
