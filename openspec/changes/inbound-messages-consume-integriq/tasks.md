# Tasks: inbound-messages-consume-integriq

Tier: V1. Kind: capability. Rows 6.5 and 6.10. Consumes integriq#2052
(`POST /api/mail-intake/import` and `MessageReceivedEvent`).

## 1. dossiq answers when integriq offers a message

- [ ] 1.1 `lib/Listener/MessageReceivedListener.php`: read the message and
  the detected reference, and write one of the three outcomes into the
  event's result slot. Never leave it unanswered: silence and a decline
  land in the same place on integriq's side, and only one of them is a
  decision.
  - `@spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md`
  - unit: a reference naming a case answers `linked`; an unknown reference
    with no fallback case type answers `declined` with the reason; the same
    message with a fallback type configured answers `created`
- [ ] 1.2 `lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`: bind the
  listener by the event's fully qualified name, the way
  `DeliveryConcludedEvent` is bound, so an instance without integriq starts
  normally.
- [ ] 1.3 Guard against filing twice. The poller and the event can both
  reach the same message on an instance running both paths; the intake
  log's per-message record already exists for exactly this and is what the
  listener checks.
  - unit: the same message id offered twice is filed once

## 2. A handler files a message on a case they pick

- [ ] 2.1 `appinfo/routes.php` and `lib/Controller/MailIntakeController.php`:
  `POST /api/mail-intake/log/{entryId}/file-on-case` taking a case id and a
  reason, behind the same intake-role guard the other four acts carry.
  - unit: a caller without the intake role is refused; a case the caller
    may not read is refused and named; the entry records who filed it
- [ ] 2.2 `src/views/intake/MailIntakeLogView.vue`: a File on a case act on
  every entry, opening a case picker. Offered on an entry that became no
  case and on one that became the wrong case, because a wrong match is the
  common reason somebody reaches for this.
- [ ] 2.3 The automatic match keeps deciding first. This act records that a
  person overrode it, and the override is a log line rather than a new
  rule, so the matcher's behaviour does not drift silently per message.

## 3. A saved mail file is read, not stored whole

- [ ] 3.1 `lib/Service/Email/SavedMailImport.php`: hand a `.eml` or `.msg`
  to integriq's `POST /api/mail-intake/import` and file the returned
  message on the case. dossiq parses nothing itself.
  - unit over a doubled client: the parsed message is filed with its
    subject, sender and date; the original file stays in the case folder
    beside it
- [ ] 3.2 An instance without integriq keeps the file and says so. A
  `class_exists` probe answers the question; a missing app must not look
  like a parse that succeeded.
  - unit: with no integriq the file is kept and the reason is recorded
- [ ] 3.3 `src/manifest.json`, widget `case-files`: a row action Read as a
  message, offered on a row whose mime says `message/rfc822` or whose name
  ends in `.msg`.

## 4. Verification

- [ ] 4.1 `tests/e2e/mail-intake-log.spec.ts`: an entry that became no case
  is filed on a case a handler picks, and the case shows the message.
- [ ] 4.2 Mutation check on 1.1: make the unknown reference answer `created`
  with no fallback type configured, and assert the decline test reddens on
  its own assertion rather than on a setup line.
- [ ] 4.3 `openspec validate inbound-messages-consume-integriq --strict`.
