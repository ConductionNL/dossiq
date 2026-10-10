# Tasks: woo-requester-notices-really-go-out

Wave 1. Row 7.11. Kind: code. Build rules: `openspec/woo-build-rules.md`.

Every task names the requirement it meets and the test that proves it. A test marked
**fails today** must be run on `origin/development` before the change and seen red; note the
failure line in the PR body.

## 1. One sender with a real result

- [x] 1.1 Add `lib/Service/Notification/RequesterNoticeSender.php` with
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
  - Built: the e-mail channel is `TermNoticeSender::send()` (termijn-notices-send, landed after this
    task was written): integriq's opt-out first, once per notice, no user and no RBAC case read, so
    it is the system-context path. Spec amended. 11 tests in RequesterNoticeSenderTest.
- [x] 1.2 Retire `BerichtenboxRoutingService::routeToBerichtenbox()`, or reduce it to channel
  selection with no `messageId` and no `sentOn` (REQ-WRN-001). Rewrite
  `tests/Unit/Service/BerichtenboxRoutingServiceTest.php` so no test expects a message id from it.
  - **fails today**: `testTheRouterNeverAnswersAMessageId` in that file. Red: `Failed asserting that an
    array does not have the key 'messageId'.` Reduced to channel selection; BeschikkingService's
    public line takes its date from the announcement date.
  - Retired with task 2.4: once `verzend()` sends through the sender, nothing called the router
    (`NoDarkCapabilityTest` named it), so the class and its test are removed.
- [x] 1.3 `TermijnNotificationService::sendTermijnNotification()` calls the sender and returns its
  result under `dispatch` (REQ-WRN-001).
  - **fails today**: `tests/Unit/Service/TermijnNotificationServiceTest.php`
    `testANoticeWithNoChannelReturnsNotSent`, built as
    `testANoticeWithNoChannelIsNotSentAndCarriesItsResult`: a sent result is returned under
    `dispatch`; a not-sent one rides on the `NoticeNotSentException` every caller already treats as
    nothing sent (`getDelivery()`), so no caller can forget to read it. Red: `Call to undefined method
    NoticeNotSentException::getDelivery()`.

## 2. Every caller acts on the result

- [x] 2.1 `AcknowledgementService::acknowledge()` writes `met` only on `sent`. On `not-sent` it
  throws a `RefusedException` carrying the reason, so `AcknowledgementDispatchJob` takes its
  existing retry path through `recordFailedAttempt()`. The public timeline line is written only on
  `sent`. On `not-sent` an INTERNAL entry says it was not sent and why (REQ-WRN-003).
  - **fails today**: `tests/Unit/Service/AcknowledgementDutyTest.php`
    `testTheDutyIsNotMetWhenNothingWentOut` and `testNoPublicSentLineWhenNothingWentOut`.
  - Red: both threw `NoticeNotSentException: The mail server did not accept the notice.` instead of a
    refusal.
  - Through the caller: `tests/Unit/BackgroundJob/AcknowledgementDispatchJobServiceAccountTest.php`
    (beside the job's existing wiring rather than a new file)
    `testThreeUnsentAttemptsLeaveTheDutyUnmet`, built on the real `AcknowledgementService`,
    not on a mock of it. Red: `'The acknowledgement of receipt could not be delivered.' contains "mail
    server"` failed.
- [x] 2.2 `InformationRequestService` suspends the term only on `sent`. On `not-sent` it goes
  through `recordFailedSend()` and answers that the term was not suspended (REQ-WRN-004).
  - **fails today**: `tests/Unit/Service/RequestInformationSuspendsTest.php`
    `testAnUnsentRequestDoesNotSuspendTheTerm`. Red: `Unknown named parameter $delivery`. The clock
    already stayed running on any throw since termijn-notices-send; the answer now carries
    `reasonCode` and the `notice` result.
- [x] 2.3 `DoorzendingNotifier`, `PauseChaseService` and `ApplicantMessage` read the result. None
  of them records a notice as sent on `not-sent` (REQ-WRN-001).
  - unit: one test per class, `testANotSentNoticeIsNotRecordedAsSent`, in
    `DoorzendingNotificationTest`, `Pause/PauseChaseServiceTest` and a new
    `Notification/ApplicantMessageTest`. These pass on the old code too: since termijn-notices-send
    all three catch the throw. They pin it. ApplicantMessage and DoorzendingNotifier now hand the
    sender the case row, so a portal requester gets the inbox.
- [x] 2.4 (defect fix, decision 148) `BeschikkingService::verzend()` sends the decision notice
  through `RequesterNoticeSender` (new `Service/Beschikking/BeschikkingDelivery`, which reads the
  case row and lends a burger addressee's BSN to a case without one). It marks the beschikking
  `sent`, starts the objection term and writes the public "Beschikking verzonden" line only on
  `sent`, storing the transport's channel and message id under `dispatch`; the sender appends the
  record to `outboundCommunications`. On `not-sent` it writes an internal "Beschikking niet
  verzonden" line with the reason code, leaves the beschikking signed and refuses
  (`beschikking-no-address` 422, `beschikking-not-sent` 503); `BeschikkingController::verzend()`
  answers that refusal.
  - **fails today**: `tests/Unit/Service/BeschikkingServiceTest.php`
    `testABeschikkingNoTransportTookIsNotMarkedSent`. Red: `verzend() must refuse when no transport
    took the beschikking. Failed asserting that null is not null.` (the status went to `sent`).
    Controller: `testVerzendNotSentAnswersTheRefusal`, red `Failed asserting that 500 is identical
    to 422.` New class test: `Beschikking/BeschikkingDeliveryTest` (5 cases).

## 3. The extension reaches the requester

- [x] 3.1 Extending a Woo term sends the `extension` template with the reason and the new end
  date. Wire it from the extension call site, not from a listener nobody dispatches. After
  `woo-term-is-computed-and-reported-right` lands, that site is the
  `WOOAssessmentController::extendDeadline()` path through `termijn#verleng`. The extension stands
  when the notice fails, and the response carries `noticeStatus` and `noticeReason`
  (REQ-WRN-005).
  - **fails today**: `tests/Unit/Controller/WOOAssessmentControllerTest.php`
    `testExtendingTellsTheRequesterWithTheReason` and
    `testAnUnsentExtensionNoticeIsReportedButTheExtensionStands`. Drive these through the
    controller. Built in `WOOAssessmentControllerExtensionNoticeTest` (own @covers set), over the real
    WOODeadlineService and the one-term-engine extension; the controller calls `ExtensionNotice`
    once the extension stands. Red on
    the old code: `Failed asserting that null is identical to 'sent'` / `'not-sent'`
    (build-round5/red-3.1.log). The answer carries `noticeStatus`, `noticeReasonCode`, `noticeReason`.
  - Check `TermLetters::render('extension', ...)` (lib/Service/Termijn/TermLetters.php line 78)
    prints the reason and the new end date. Test:
    `tests/Unit/Service/Termijn/TermLettersTest.php` `testTheExtensionLetterCarriesItsReason`. Done.

## 4. The record on the case

- [x] 4.1 Declare the `outboundCommunications` item keys of REQ-WRN-006 on the case schema in
  `lib/Settings/dossiq_register.json` and bump the register version (REQ-WRN-006).
  - unit: `tests/Unit/Woo/WooWritesMatchTheRealSchemasTest.php`
    `testANoticeRecordValidatesAgainstTheCaseSchema`, using `tests/Support/RealSchemaValidator`
    on the real record the sender writes. Do not use a hand-built array. Built in
    `RequesterNoticeSenderTest::testANoticeRecordValidatesAgainstTheCaseSchema` (sent and not-sent
    records). The keys live in the fragment `register.d/36-ontvangstbevestiging.json` where
    `outboundCommunications` is declared; case 1.39.0, register 0.20.22, digests regenerated.
- [x] 4.2 Stage notices on a Woo status change each append their own record (REQ-WRN-006).
  - unit: `testEachStageNoticeIsStoredWithItsOwnResult` in `RequesterNoticeSenderTest`.

## 5. End to end

- [ ] 5.1 (live pass, decision 139; spec written) `tests/e2e/woo-requester-notices.spec.ts`: a Woo request from the portal gets its
  acknowledgement in the portal inbox and the case shows the duty met. A case with no address
  shows the duty unmet and no "verzonden" line on the citizen timeline. Cite REQ-WRN-002 and
  REQ-WRN-003.
- [ ] 5.2 (live pass, decision 139) Live check after merge on the dev instance: one Woo case with no address. Read
  `acknowledgementDuty` through the OpenRegister API and the public timeline through the portal.
  Record both in the PR or issue.

## 6. Verify and deliver

- [x] 6.1 `TMPDIR` set to a sibling directory beside the clone, never inside it.
- [x] 6.2 While building, run the unit tests of the touched classes with
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
