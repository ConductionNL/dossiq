# Tasks: inbound-messages-consume-integriq

Tier: V1. Kind: capability. Rows 6.5 and 6.10. Consumes integriq#2052
(`MessageReceivedEvent` and the mail reader behind
`POST /api/mail-intake/import`).

## 1. dossiq answers when integriq offers a message

- [x] 1.1 `lib/Listener/MessageReceivedListener.php`: read the message and the
  detected reference, and write one of the three outcomes into the event's
  result slot.
  - `@spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md`
  - unit: a reference naming a case answers `linked` AND files the message; an
    unknown reference with no fallback case type answers `declined` with the
    reason recorded; the same message with a fallback type answers `created`.
    Nine arms in all.
  - **IT IS NOT A SECOND MATCHER.** The reference integriq detected is what
    decides, resolved through `CaseEmailRepository::findCaseIdByIdentifier`,
    and the fallback is `UnmatchedMailIntake`'s, which is the same one the
    mailbox poller uses. Two matchers disagree the first time either is tuned.
  - **THE REASON GOES IN DOSSIQ'S OWN LOG, and that is measured rather than
    chosen.** integriq's `setOutcome()` takes an outcome and an object
    reference and has NO slot for a reason: read at `development` on
    2026-09-18. So the decline that reaches integriq is bare, and the sentence
    saying why is on the intake log entry this listener writes first. The
    requirement is reworded to say that rather than describing a field that
    does not exist.
  - **AN INTERNAL FAILURE LEAVES THE SLOT EMPTY, deliberately.** It is the one
    place this listener does not answer: after a crash no decision was made,
    and writing `declined` would be this app claiming a judgement it never
    reached. The spec now says so, and an arm asserts it.
- [x] 1.2 `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`: bound by the
  event's fully qualified name behind `class_exists`, the way
  `DeliveryConcludedEvent` is, so an instance without integriq starts normally
  and the listener is never constructed. A test asserts the constant equals
  the class this test file imports: a listener bound to an event nobody fires
  is indistinguishable from one that works.
  - It is a DIFFERENT event from `IntakeMessageRoutedEvent`, and the two
    listeners are not duplicates. That one answers a routing rule that already
    decided a message belongs to a case schema; this one is offered a message
    and its detected reference and has to decide.
- [x] 1.3 Guard against filing twice, using the intake log's per-message
  record, which is what `findChannelEntry` exists for.
  - unit: the same message id offered twice is filed once, and the SAME case
    is answered back rather than a decline, because declining would send a
    message integriq already has a home for back to `unassigned`. A second arm
    covers a message seen before that became no case, which is declined:
    `setOutcome('linked', '')` throws in integriq's own contract.
  - The channel is `integriq-message`, its own rather than the poller's, so an
    instance running BOTH paths can tell which one saw a message first.

## 2. A handler files a message on a case they pick

- [x] 2.1 `appinfo/routes.php` and `lib/Controller/MailIntakeController.php`:
  `POST /api/mail-intake/log/{entryId}/file-on-case` taking a case id and a
  reason, behind the same intake-role guard the other four acts carry.
  - unit: a caller without the intake role is refused (and the existing
    every-endpoint sweep gains this act); a case the caller may not read is
    refused AND named; a missing reason is refused; and a handler with both
    guards satisfied moves the message while the entry records who did it,
    why, and where it was before.
  - **TWO GUARDS, not one.** The intake role says who may work the log at all;
    `CaseAccessGuard` says whether THIS caller may see THIS case. Without the
    second, anyone with the intake role could file a message onto any case id
    they cared to guess, and the case would then show a citizen's message to a
    handler who may not read it.
  - The reason is REQUIRED. This act overrides a decision the matcher made,
    and an override nobody accounted for is an audit finding waiting to
    happen.
- [x] 2.2 `src/views/intake/MailIntakeLogView.vue`: a File on a case act on
  every entry, opening a picker. Offered on an entry that became NO case and
  on one that became the WRONG case, because a wrong match is the common
  reason somebody reaches for this and an entry that became 2026-090 when it
  belonged on 2026-114 looks exactly like a successful match until a person
  reads it.
  - The case field is PRE-FILLED with where the message is now, not blank: a
    handler who has to retype the right case beside a field that forgot the
    wrong one cannot see what they are changing.
  - A 403 does NOT close the dialog. Closing it would tell the handler the
    message moved when it did not.
- [x] 2.3 The automatic match keeps deciding first. This act writes a log line
  on one entry and changes no rule, so the matcher's behaviour does not drift
  silently per message. The dialog says so in words, beside the case the
  matcher chose.

## 3. A saved mail file is read, not stored whole

- [x] 3.1 `lib/Service/Email/SavedMailImport.php`: hand a `.eml` or `.msg` to
  integriq's reader and file the returned message on the case. dossiq parses
  nothing itself.
  - unit over a stand-in parser: the parsed message is filed with its subject,
    sender and date; a message with no subject is titled after its file,
    because an untitled row is a row nobody can pick out of five others.
  - **IT CALLS INTEGRIQ IN PROCESS, NOT OVER `POST /api/mail-intake/import`,
    and the task named the endpoint.** That endpoint is the same parser behind
    a session and a multipart upload, and reaching it from PHP would mean
    forwarding the caller's session to our own server.
    `FleetAppId::getService()` resolves `Service\Mail\MessageParser` under
    whichever namespace this instance's integriq has, which is the same
    rename-proof lookup every other cross-app call here uses. The import
    endpoint stays what a browser would use.
  - The original file is never moved or deleted. It is the record, and an
    archive that kept only the reading cannot answer a question about the
    bytes later on.
- [x] 3.2 An instance without integriq keeps the file and says so, probed
  through `FleetAppId`.
  - unit: with no integriq the file is kept, NOTHING is filed, and the reason
    names the missing app. Three more arms cover an integriq with no reader,
    a parser that throws, and an empty file, each with its own sentence,
    because one reader has to install integriq and another has to upgrade it.
- [x] 3.3 `src/manifest.json`, widget `case-files`: a Read as a message row
  action.
  - **A FUNCTION HANDLER, not a declarative act, and it is offered on EVERY
    row.** CnFilesBrowser's row-action vocabulary is `open-modal` and
    `handler` and holds no `api-call`, so a declared POST would have rendered
    a menu item that does nothing when clicked. It also has no per-row
    condition at all, so the mime-or-name check the task asked for cannot be a
    `visibleWhen`: it lives in the handler and answers with a sentence rather
    than by being absent.
  - The SERVER decides what a file really is. integriq's reader looks at the
    bytes, so a `.msg` renamed `.eml` still parses as what it is; the
    name check here only decides which rows are worth offering the gesture on.

## 4. Verification

- [x] 4.1 `tests/e2e/mail-intake-log.spec.ts`: the log offers File on a case
  on every entry, the picker takes a case and a reason, and the confirm button
  is disabled without one. Two more arms probe the file-on-case endpoint and
  the read-as-message endpoint anonymously and on an unreachable file id.
  Written and tagged, not run: there is no Playwright run on this box.
- [x] 4.2 Mutation checks, two, each reddening an assertion rather than a
  setup line:
  - `SavedMailImport::kept()` returning an `imported` outcome reddens all four
    kept arms on their own outcome assertion. That is "a missing app looked
    like a parse that succeeded".
  - `readFileAsMessage` treating every 200 as a send reddens "shows the reason
    and no success when the file was only kept" on
    `expect(mockSuccess).not.toHaveBeenCalled()`.
  - The 1.1 mutation the task asked for is covered structurally instead: the
    decline arms assert the RECORDED REASON and the outcome by name, so a
    listener answering `created` with no fallback configured reddens on
    `assertSame('declined', $event->getOutcome())` and on the log entry
    beside it.
- [x] 4.3 `openspec validate inbound-messages-consume-integriq --strict`.

## What was NOT built here, and who has it

The listener for `IntakeMessageRoutedEvent` is another lane's
(`an-intake-message-opens-a-case`, merged as dossiq#2991) and is not touched.
This change binds `MessageReceivedEvent`, which is a different question: that
one answers a routing rule that already decided a message belongs to a case
schema, this one is offered a message and its detected reference and has to
decide.
