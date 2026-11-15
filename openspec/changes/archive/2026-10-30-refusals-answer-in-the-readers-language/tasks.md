# Tasks: refusals-answer-in-the-readers-language

## 1. Refusals in the reader's language

- [x] 1.1 `TranslatesRefusals::refusalSentence()` translates the sentence through the controller's `IL10N`, else the app's translator; unchanged when there is none or when formatting fails
  - Spec ref: specs/frontend-locale-completeness/spec.md, "A refusal MUST reach the reader in their language"
  - Files: lib/Controller/Support/TranslatesRefusals.php, lib/Controller/StatusTransitionController.php
  - Test: tests/Unit/Controller/RefusalCarriesAStatusTest.php (`testARefusalAnswersInTheReadersLanguage`, `testARefusalWithoutATranslatorKeepsItsSentence`), through the real controller and the shipped nl catalogue
