# Design: communication-portal-conversation-on-the-case

Read at dossiq `development` `db27acb6e` and portaliq `development` on
2026-09-27.

## What is there

- `PortalContributionProvider::citizenCollections()` declares `berichten`:
  `kind: inbox`, schema `portaalBericht`, `scopeField: recipientRef`, fields
  `caseReference`, `senderType`, `senderName`, `subject`, `content`,
  `attachments`, `direction`, `sentAt`, `readByRecipientAt`.
- `citizenActions()` declares `replyToMessage`: create on `portaalBericht`,
  `scopeField: senderRef`, fields `subject`, `content`, `attachments`, `caseId`,
  defaults `direction: citizen_to_handler`, `senderType: burger`, and `caseId` a
  required cross reference to the resident's own cases.
- `mijnZaken` has a `detail` card and a `timeline` provider (`caseTimeline`).
- portaliq: `PortalInboxReader` merges every inbox collection; `InboxPage.jsx`
  renders `subject`, `body` and `receivedAt`, and `markRead()` writes the literal
  `{read: true}` (`ContributionController.php:400-413`). `optionsProviders` of
  type `collection` fetch through the subject-scoped collection endpoint
  (`contribution-manifest-v3`).
- `portaalBericht.attachments` holds OpenRegister document ids. `senderType` is
  `burger`, `bedrijf` or `authorisedRepresentative`; a handler has no value.
- `TimelineKinds::PORTAL_MESSAGE` (`lib/Service/Timeline/TimelineKinds.php:100`)
  exists for outbound messages (`one-timeline-on-the-case`). Nothing under
  `src/` reads `portaalBericht`.

## D-1. Speak the inbox's vocabulary, keep one store

`portaalBericht` gains `body` and `receivedAt` as calculations over `content` and
`sentAt` (`x-openregister-calculations`, the way `case.statusPublicLabel` is
calculated), and `read`, a boolean the resident's `markRead()` writes. A listener
on the object update stamps `readByRecipientAt` the first time `read` turns true,
so the handler keeps the moment. `berichten` projects `body`, `receivedAt` and
`read`. No field is renamed: renaming would break every stored message and the
repair step that wrote them. If the calculation engine cannot copy a scalar, the
fallback is to write `body` and `receivedAt` in the same save as their source,
from the provider's one writer, and task 1.1 says which.

## D-2. Attachments are files on the message

portaliq serves files that live in the object's own folder (`filesDownload`,
`_files`) and uploads a reply's files there. The message's files become that
folder. `berichten` declares `filesDownload: true`; `replyToMessage` declares
`fieldConfigs.attachments` with `type: file`, `multiple: true`, the accepted types
of the case upload and a size limit of 20 MB. A repair step copies the file of
every document id already in `attachments` into its message's folder, once.

## D-3. The reply carries the case

`berichten` declares `reply: {action: replyToMessage, carry: {caseId: caseId},
subjectFrom: subject}` (portaliq's D2 contract). The server carries `caseId` from
the message the resident owns; the client cannot change it. For a message the
resident starts, `replyToMessage` declares
`optionsProviders.caseId: {type: collection, register: dossiq, schema: case,
labelField: title, valueField: id}`, which portaliq fetches through the resident's
own scoped `mijnZaken`, so the resident picks a case by its title.

## D-4. Ask from the case, read the answer there

- A create action `askAboutCase` on `portaalBericht`, fields `subject`, `body`
  mapped to `content`, `attachments`, with `caseId` taken from the detail row it
  is started from and checked by the same cross reference guard.
- `mijnZaken.detail` offers it as a detail action and gains `messages:
  {label: 'Berichten', provider: 'caseMessages'}`, a provider method like
  `caseTimeline()` that answers the resident's messages about that case, both
  directions, newest first.

Both need portaliq to render them (sibling half, to be specified). Until it
does, the declarations are inert and the reply from the inbox (D-3) already
answers the tender row.

## D-5. The handler sees it and answers from the case

- A listener on `portaalBericht` creation with `direction: citizen_to_handler`
  records a `portaalbericht` timeline entry on the case (`one-timeline-on-the-case`
  D4), internal, with a follow-up: a question from a resident is open until a
  handler answers it.
- `#CaseDetail` gains a header action "Message the applicant" (visible when the
  case has a `portalSubject`) opening `PortalMessageDialog.vue`, which writes a
  `portaalBericht` with `direction: handler_to_citizen`, `recipientRef` the
  case's `portalSubject`, `caseId`, `caseReference` and `senderType: medewerker`,
  a value added to the enum. The timeline entry of a resident's message offers
  Reply, which opens the same dialog with the subject prefilled. Sending closes
  the follow-up.
- The outbound message is a public `portaalbericht` entry, as
  `one-timeline-on-the-case` D5 already rules.

## Risks

- `recipientRef` means the resident on a handler's message and the handler on a
  resident's. The inbox is scoped on it, so a handler's message must carry the
  resident's `portalSubject` there, never a user id. A unit test pins it.
- Until portaliq ships `reply`, `_files` and the detail action, parts of this
  are declared and not rendered. Each declaration is inert when unread, and the
  handler side works on its own.
