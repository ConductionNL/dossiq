# Tasks: communication-portal-conversation-on-the-case

Tier: V1. Kind: code. Rows: portaliq `cmp-act-ask-question-case`,
`dem-tnd-portal-talk-on-case`, `sib-dossiq-6-7`; dossiq 6.7.

## 1. The inbox vocabulary

- [ ] 1.1 `portaalBericht`: `body` and `receivedAt` as calculations over
  `content` and `sentAt`, `read` as a boolean; `berichten` projects the three
  (design D-1). Record in the PR whether the calculation copies a scalar.
  - unit: `PortalContributionProviderTest` asserts the projected fields;
    a schema test asserts the three properties
- [ ] 1.2 Listener: stamp `readByRecipientAt` the first time `read` turns true.
  - unit: stamped once, not moved by a second read
- [ ] 1.3 Live check: a message written by dossiq shows its body and date in
  portaliq's inbox, and Mark as read sets `readByRecipientAt`.

## 2. Attachments

- [ ] 2.1 `filesDownload: true` on `berichten`; `fieldConfigs.attachments` of
  `type: file` on `replyToMessage` (design D-2).
  - unit: the provider test asserts both declarations
- [ ] 2.2 Repair step: copy each document id's file in `attachments` into its
  message's folder; idempotent; post-migration with a version key (ADR-106).
  - unit: run twice, one copy per file

## 3. Reply and case choice

- [ ] 3.1 `reply` on `berichten` carrying `caseId`, and
  `optionsProviders.caseId` on `replyToMessage` (design D-3).
  - unit: the provider test asserts both shapes against portaliq's contract

## 4. Ask from the case

- [ ] 4.1 `askAboutCase` action, the detail action on `mijnZaken` and the
  `caseMessages` provider (design D-4).
  - unit: `caseMessages` answers only the resident's messages for that case,
    newest first; a foreign case id is refused by the cross reference guard

## 5. The handler side

- [ ] 5.1 Listener: a resident's `portaalBericht` records an internal
  `portaalbericht` timeline entry with a follow-up (design D-5).
  - unit: entry kind, visibility, follow-up and the message id in `fields`
- [ ] 5.2 `senderType` gains `medewerker`; `PortalMessageDialog.vue` and the
  header action "Message the applicant"; Reply on the timeline entry; sending
  closes the follow-up.
  - vitest: the dialog writes `handler_to_citizen` with `recipientRef` equal to
    the case's `portalSubject`
  - `npm run check:manifest` and `npm run lint` exit 0
- [ ] 5.3 `tests/e2e/portal-conversation-on-the-case.spec.ts`: a handler
  answers a resident's message from the case; citing the scenarios below.
