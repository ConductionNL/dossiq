# A reply to the resident can be kept as a draft

## Why

DqZaakContact draws "Opslaan als concept" beside "Versturen" under the answer
to the resident (coordinator decision 24 Oct). A handler who starts an answer
and is called away keeps it on the case, across a reload or another device.

## What changes

- GET/PUT /api/cases/{id}/reply-draft: the caller's draft on the case, kept
  in their own user settings (so nobody else reads it), after the case read
  check; an empty text clears it, a text over 20,000 characters is refused.
- The case's resident thread declares `content.draft.url`, read by nc-vue's
  conversation widget (feat/conversation-reply-draft).
- PortalMessageDialog announces a sent reply on the event bus
  (`cn:conversation:reply-sent` `{ objectId }`), so the conversation widget
  clears the saved draft and the reply box.
