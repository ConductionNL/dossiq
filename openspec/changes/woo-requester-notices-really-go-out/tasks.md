# Tasks: woo-requester-notices-really-go-out

Wave 1. Row 7.11. Kind: code. Build rules: `openspec/woo-build-rules.md`.

Every task names the requirement it meets and the test that proves it. A test marked
**fails today** must be run on `origin/development` before the change and seen red; note the
failure line in the PR body.

## 1. One sender with a real result

- [ ] 1.1 Add `lib/Service/Notification/RequesterNoticeSender.php` with
  `send(array $case, string $template, array $rendered, string $moment): array` returning
  `{status: 'sent'|'not-sent', channel, messageId?, sentAt?, reasonCode?, reason?, channelsTried: list<{channel, reasonCode, reason}>}`.
  It calls the real transports: a `portaalBericht` write in dossiq's register
  (`portaal_bericht_schema`), `CaseEmailService::sendEmail()`, and
  `BerichtenboxService::sendMessage()`. Read each real signature before mocking it:
  `CaseEmailService::sendEmail()` throws `RuntimeException` and `RecipientOptedOutException`, and
  `BerichtenboxService::sendMessage()` returns an `error` payload or a record whose adapter result
  carries `refused: true` (REQ-WRN-001, REQ-WRN-002).
  - unit `tests/Unit/Service/Notification/RequesterNoticeSenderTest.php`:
    `testNoChannelAnswersNotSentWithoutAMessageId`,
    `testAnIntegriqRefusalFallsThroughToEmailAndIsListedAsTried`,
    `testAPortalRequesterGetsAPortalMessageAndNoDigitalPost`,
    `testAThrowingMailerIsNotSentWithItsReason`.
  - The e-mail send runs from a background job with no user. `sendEmail()` loads the case with
    RBAC on and refuses when nothing is found, so the sender needs a system-context path. Test it:
    `testTheEmailChannelWorksFromABackgroundJobWithNoUser`.
- [ ] 1.2 Retire `BerichtenboxRoutingService::routeToBerichtenbox()`, or reduce it to channel
  selection with no `messageId` and no `sentOn` (REQ-WRN-001). Rewrite
  `tests/Unit/Service/BerichtenboxRoutingServiceTest.php` so no test expects a message id from it.
  - **fails today**: `testTheRouterNeverAnswersAMessageId` in that file.
- [ ] 1.3 `TermijnNotificationService::sendTermijnNotification()` calls the sender and returns its
  result under `dispatch` (REQ-WRN-001).
  - **fails today**: `tests/Unit/Service/TermijnNotificationServiceTest.php`
    `testANoticeWithNoChannelReturnsNotSent`.

## 2. Every caller acts on the result

- [ ] 2.1 `AcknowledgementService::acknowledge()` writes `met` only on `sent`. On `not-sent` it
  throws a `RefusedException` carrying the reason, so `AcknowledgementDispatchJob` takes its
  existing retry path through `recordFailedAttempt()`. The public timeline line is written only on
  `sent`. On `not-sent` an INTERNAL entry says it was not sent and why (REQ-WRN-003).
  - **fails today**: `tests/Unit/Service/AcknowledgementDutyTest.php`
    `testTheDutyIsNotMetWhenNothingWentOut` and `testNoPublicSentLineWhenNothingWentOut`.
  - Through the caller: `tests/Unit/BackgroundJob/AcknowledgementDispatchJobTest.php`
    `testThreeUnsentAttemptsLeaveTheDutyUnmet`, built on the real `AcknowledgementService`,
    not on a mock of it.
- [ ] 2.2 `InformationRequestService` suspends the term only on `sent`. On `not-sent` it goes
  through `recordFailedSend()` and answers that the term was not suspended (REQ-WRN-004).
  - **fails today**: `tests/Unit/Service/RequestInformationSuspendsTest.php`
    `testAnUnsentRequestDoesNotSuspendTheTerm`.
- [ ] 2.3 `DoorzendingNotifier`, `PauseChaseService` and `ApplicantMessage` read the result. None
  of them records a notice as sent on `not-sent` (REQ-WRN-001).
  - unit: one test per class, `testANotSentNoticeIsNotRecordedAsSent`, in
    `DoorzendingNotificationTest`, `Pause/PauseChaseServiceTest` and a new
    `Notification/ApplicantMessageTest`.

## 3. The extension reaches the requester

- [ ] 3.1 Extending a Woo term sends the `extension` template with the reason and the new end
  date. Wire it from the extension call site, not from a listener nobody dispatches. After
  `woo-term-is-computed-and-reported-right` lands, that site is the
  `WOOAssessmentController::extendDeadline()` path through `termijn#verleng`. The extension stands
  when the notice fails, and the response carries `noticeStatus` and `noticeReason`
  (REQ-WRN-005).
  - **fails today**: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testExtendingTellsTheRequesterWithTheReason` and
    `testAnUnsentExtensionNoticeIsReportedButTheExtensionStands`. Drive these through the
    controller.
  - Check `TermLetters::render('extension', ...)` (lib/Service/Termijn/TermLetters.php line 78)
    prints the reason and the new end date. Test:
    `tests/Unit/Service/Termijn/TermLettersTest.php` `testTheExtensionLetterCarriesItsReason`.

## 4. The record on the case

- [ ] 4.1 Declare the `outboundCommunications` item keys of REQ-WRN-006 on the case schema in
  `lib/Settings/dossiq_register.json` and bump the register version (REQ-WRN-006).
  - unit: `tests/Unit/Woo/WooWritesMatchTheRealSchemasTest.php`
    `testANoticeRecordValidatesAgainstTheCaseSchema`, using `tests/Support/RealSchemaValidator`
    on the real record the sender writes. Do not use a hand-built array.
- [ ] 4.2 Stage notices on a Woo status change each append their own record (REQ-WRN-006).
  - unit: `testEachStageNoticeIsStoredWithItsOwnResult` in `RequesterNoticeSenderTest`.

## 5. End to end

- [ ] 5.1 `tests/e2e/woo-requester-notices.spec.ts`: a Woo request from the portal gets its
  acknowledgement in the portal inbox and the case shows the duty met. A case with no address
  shows the duty unmet and no "verzonden" line on the citizen timeline. Cite REQ-WRN-002 and
  REQ-WRN-003.
- [ ] 5.2 Live check after merge on the dev instance: one Woo case with no address. Read
  `acknowledgementDuty` through the OpenRegister API and the public timeline through the portal.
  Record both in the PR or issue.

## 6. Verify and deliver

- [ ] 6.1 `TMPDIR` set to a sibling directory beside the clone, never inside it.
- [ ] 6.2 While building, run the unit tests of the touched classes with
  `./vendor/bin/phpunit -c phpunit-unit.xml --no-coverage --filter '<Class>'`. Judge by the
  `Tests:` line, because a green suite exits 1 without a coverage driver.
- [ ] 6.3 Before push, once: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then
  `npm run lint`, `npm run format`, `npm run check:l10n-js`, `npm run check:schema-l10n` and
  `npm run check:manifest`. Run any other leg that `code-quality.yml` requires, after checking
  `package.json`. Then run hydra's `scripts/run-hydra-gates.sh --base origin/development` and
  count the gates that ran.
- [ ] 6.4 The coverage guard needs tests for every added statement. Project coverage of the added statements. When no coverage driver (xdebug or pcov) is available, take the base percentages from `development`'s last green push run, intersect its clover uncovered lines with the lines this branch adds, and say in the PR body that the number is projected, not measured.
- [ ] 6.5 One PR, `--base development`. Merge development in, never rebase. No `Co-Authored-By`
  on any commit. Done means merged on `development` with CI green. Row 7.11 moves to `yes` (build)
  then, and to `production` only with a store release.
