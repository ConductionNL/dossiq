# Tasks: woo-dossier-shared-with-the-requester

Read `openspec/woo-build-rules.md` first. Start the term tasks (group 2) only once `one-term-engine` PR 4 ("Woo on the generic engine") is merged on `development`; read the case `deadline` it writes, compute nothing. One PR per group is fine; `--base development`.

## 1. Answer from the portal

- [x] 1.1 (`CitizenManifest::answerQuestionAction()`, `rowActions` on `vragenAanU`; `tests/Unit/Portal/CitizenManifestTest.php`) Declare `beantwoordVraag` on `vragenAanU` in `CitizenManifest` (REQ-WDS-001). Verify: `CitizenManifestTest::testTheQuestionsCollectionOffersAnAnswer`.
- [x] 1.2 (`lib/Controller/PortalWooAnswerController.php`, route `portalWooAnswer#answer`, `lib/Portal/PortalWooAnswer.php`, act `answer` in `ApplicantPortalActs`; `tests/Unit/Portal/PortalWooAnswerTest.php` drives the controller with the real services. Attachments are NOT carried: portaliq's row forward passes params only, spec amended, sibling ask filed) Add the portal route and its handler: subject check, `recordAnswer(complete: false)`, attachments filed as incoming documents, `ApplicantPortalActs::recordWrite(act: 'answer')` (REQ-WDS-001). Verify: `tests/Unit/Portal/PortalWooAnswerTest.php::testTheAnswerLandsOnTheOpenRequest`, `::testAnotherSubjectsRequestIsRefused`, `::testAClosedRequestIsRefused`, `::testTheTermStaysPaused`.

## 2. Term on the portal case (after one-term-engine PR 4)

- [x] 2.0 (`lib/Settings/register.d/85-woo-dossier-shared.json` declares both on `case`, register 0.20.22; both on `CITIZEN_CASE_FIELDS`; `tests/Unit/Woo/WooPortalResultLinkTest.php::testTheCaseDeclaresTheTermNoteAndTheResultLink`) Declare `termNote` and `resultLink` on the `case` schema in `lib/Settings/register.d/76-portal-citizen-writes.json`, in the exact shapes of portaliq's `portalCase` (`portaliq/woo-dossier-in-my-cases` REQ-WDM-002 and REQ-WDM-003), bump the version, and add both to the fields of the citizen cases collection. Verify: a register test that saves both on a case and reads them back (an undeclared key is dropped on save).
- [ ] 2.1 Write `legalDecisionDate` and `termNote` on every Woo case save (REQ-WDS-002). Verify: `tests/Unit/Portal/WooPortalTermNoteTest.php::testAPausedTermSaysWhyAndGivesNoDate`, `::testAnExtensionCarriesItsReason`, `::testARunningTermHasNoNote`.

## 3. Result link

- [x] 3.1 (`lib/Woo/WooResultLink.php` through `WooCaseLedger::writeCaseState()` and the backfill repair; `tests/Unit/Woo/WooPortalResultLinkTest.php`) Write and clear `resultLink` from `wooPublicationStatus` (REQ-WDS-003). Verify: `tests/Unit/Portal/WooPortalResultLinkTest.php::testPublishingWritesTheLink`, `::testWithdrawingClearsTheLink`.

## 4. Clarification

- [x] 4.1 (`AanvullingsverzoekService::ask(question:)`, the desk sends `kind: verduidelijking` and `question`, refused before any letter on another case type or with documents; `CaseTermsController::requestInformation` passes `kind` and `question`; `tests/Unit/Service/WooClarificationTest.php`) Add the kind `verduidelijking` to `aanvullingsverzoek`, Woo case type only, without items (REQ-WDS-004). Verify: `tests/Unit/Service/WooClarificationTest.php`.

## 5. Nothing from the desk

- [x] 5.1 (fails on an added `wooAssessment` field: dq-l10-logs/red-nodeskwork.log) Add `tests/Unit/Portal/WooRequesterSeesNoDeskWorkTest.php` (REQ-WDS-005). Verify: it fails when a `wooAssessment` field is added to any citizen collection.

## 6. End to end

- [ ] 6.1 (live pass, decision 139) `tests/e2e/woo-dossier-shared-with-the-requester.spec.ts` with portaliq installed: the three scenarios marked e2e. Verify: the run, against the board `portaliq/ZaakWooVerzoek`.
- [ ] 6.2 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`. No `Co-Authored-By`.
