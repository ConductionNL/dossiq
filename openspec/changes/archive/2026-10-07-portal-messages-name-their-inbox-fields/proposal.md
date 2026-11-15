---
kind: code
depends_on: []
---

# Proposal: portal-messages-name-their-inbox-fields

## Why

A message the organisation sends to a resident reached the portal inbox as a subject line only (ConductionNL/portaliq#702). The inbox reads `body`, `receivedAt` and `read`. dossiq's `portaalBericht` keeps them as `content`, `sentAt` and `readByRecipientAt`, so the text and the date stayed blank, every message sorted last and none ever read as read. Attachments never showed.

portaliq now lets an inbox collection name its own fields (`messageFields`, portaliq change `inbox-reads-each-apps-message-fields`) and lists a message's files when the collection declares `filesDownload`.

## What changes

- The citizen `berichten` collection declares `messageFields` (`body: content`, `receivedAt: sentAt`, `readAt: readByRecipientAt`, `attachments: attachments`) and `filesDownload: true`.
- The supplier `messages` collection declares `messageFields` (`receivedAt: sentAt`, `attachments: attachmentRefs`). Its text is already `body`.
- No schema changes. Stored messages keep their field names.

## Out of scope

- A dossiq screen for a handler to write a portal message. Today a handler message is written through OpenRegister.
- A read date on `supplierMessage`. It has none, so a supplier's message stays unread in the portal.
