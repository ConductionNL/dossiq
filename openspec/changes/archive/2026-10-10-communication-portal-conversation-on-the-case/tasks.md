# Tasks: communication-portal-conversation-on-the-case

Tier: V1. Kind: code. Rows: portaliq `cmp-act-ask-question-case`,
`dem-tnd-portal-talk-on-case`, `sib-dossiq-6-7`; dossiq 6.7.

## 1. The inbox vocabulary

- [x] 1.1 `portaalBericht`: `body` and `receivedAt` as calculations over
  `content` and `sentAt`, `read` as a boolean; `berichten` projects the three
  (design D-1). Record in the PR whether the calculation copies a scalar.
  - unit: `PortalContributionProviderTest` asserts the projected fields;
    a schema test asserts the three properties
  - Done another way: `berichten` names its own fields through `messageFields`
    (`body` -> `content`, `receivedAt` -> `sentAt`, `readAt` ->
    `readByRecipientAt`), which portaliq copies onto the inbox row
    (portaliq `InboxMessageFields`). No calculation was added, so no copy was
    stored twice. Built by the archived `portal-messages-name-their-inbox-fields`;
    test `PortalContributionProviderTest::testTheInboxNamesItsMessageFields`.
    For the record: the calculation engine does copy a scalar (`{prop: ...}`,
    as `case.statutoryTerm` does).
- [x] 1.2 Listener: stamp `readByRecipientAt` the first time `read` turns true.
  - unit: stamped once, not moved by a second read
  - Done another way: portaliq's mark-read writes the current time into the
    field `messageFields.readAt` names, and only when it is still empty, so
    the first moment stays (portaliq `InboxMessageFields::readPayload()`). A
    dossiq listener would have been a second writer of the same field.
- [ ] 1.3 Live check (not run: needs a live instance with portaliq): a message written by dossiq shows its body and date in (live pass, decision 139; archived 10 Oct, recipe in dossiq STATE.md "Still owed")
  portaliq's inbox, and Mark as read sets `readByRecipientAt`.

## 2. Attachments

- [x] 2.1 `filesDownload: true` on `berichten`; `fieldConfigs.attachments` of
  `type: file` on `replyToMessage` (design D-2).
  - unit: the provider test asserts both declarations
  - `PortalConversation::ATTACHMENTS_FIELD`; `PortalConversationTest::testAttachmentsAreFilesBothWays`
- [x] 2.2 Repair step: copy each document id's file in `attachments` into its
  message's folder; idempotent; post-migration with a version key (ADR-106).
  - unit: run twice, one copy per file
  - `lib/Repair/CopyMessageAttachmentsIntoMessageFolders.php` (a record uuid or a bare file id; copies, never moves), registered in post-migration; `CopyMessageAttachmentsIntoMessageFoldersTest`

## 3. Reply and case choice

- [x] 3.1 `reply` on `berichten` carrying `caseId`, and
  `optionsProviders.caseId` on `replyToMessage` (design D-3).
  - unit: the provider test asserts both shapes against portaliq's contract
  - `PortalConversation::inboxReply()`, `replyAction()`; `caseId` joins the inbox
    projection so portaliq keeps the carry. `PortalConversationTest`

## 4. Ask from the case

- [x] 4.1 `askAboutCase` action, the detail action on `mijnZaken` and the
  `caseMessages` provider (design D-4).
  - unit: `caseMessages` answers only the resident's messages for that case,
    newest first; a foreign case id is refused by the cross reference guard
  - `PortalConversation::askAction()`, `mijnZaken.messages` and
    `detail.actions`, `PortalCaseMessages` behind
    `PortalContributionProvider::caseMessages()`. `PortalCaseMessagesTest`,
    `PortalConversationTest::testAResidentAsksFromTheCase`

## 5. The handler side

- [x] 5.1 Listener: a resident's `portaalBericht` records an internal
  `portaalbericht` timeline entry with a follow-up (design D-5).
  - unit: entry kind, visibility, follow-up and the message id in `fields`
  - `PortalMessageTimelineListener`, registered in `ContactListenerRegistrar`.
    The entry is a new kind `portaalbericht-inkomend` (follow-up on), not
    `portaalbericht`: a follow-up is declared per kind, and the outbound
    `portaalbericht` must not open one. `PortalMessageTimelineListenerTest`.
- [x] 5.2 `senderType` gains `medewerker`; `PortalMessageDialog.vue` and the
  header action "Message the applicant"; Reply on the timeline entry; sending
  closes the follow-up.
  - vitest: the dialog writes `handler_to_citizen` with `recipientRef` equal to
    the case's `portalSubject`
  - `npm run check:manifest` and `npm run lint` exit 0
  - `src/dialogs/PortalMessageDialog.vue`, the `message-applicant` header action
    (visible on a case with a `portalSubject`), Reply on a
    `portaalbericht-inkomend` entry in `CaseTimelineTab.vue`; the listener
    records the handler's message as a public `portaalbericht` entry.
    `tests/vitest/portalMessageDialog.spec.js` validates the payload against
    the real `portaalBericht` fragment.
- [ ] 5.3 `tests/e2e/portal-conversation-on-the-case.spec.ts` (written, not run: needs a live instance): a handler (live pass, decision 139; archived 10 Oct, recipe in dossiq STATE.md "Still owed")
  answers a resident's message from the case; citing the scenarios below.
