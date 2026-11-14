# Tasks: the-redaction-stage-waits-for-every-redacted-copy

- [x] 1.1 `documentAssessment` guard: `require: redacted`, read regardless of the caller, fails closed
  - Spec ref: specs/status-transition-engine/spec.md, "A move MAY require every partly public document redacted"
  - Files: lib/Service/Transitions/DocumentAssessmentGuard.php, lib/Service/WOODocumentAssessmentService.php (`partlyPublicWithoutCopy()`), l10n
  - Test: tests/Unit/Service/Transitions/DocumentAssessmentGuardRedactedTest.php through the real assessment service
- [x] 1.2 The Woo flow requires it on "Besluit voorbereiden"; package `woo-verzoek` 1.1.0
  - Files: lib/Settings/packages/woo-verzoek/flows/woo-verzoek.json, lib/Settings/packages/woo-verzoek/package.json
  - Test: tests/Unit/Settings/WooFlowGuardsTest.php (`testTheDecisionWaitsForEveryRedactedCopy`)
