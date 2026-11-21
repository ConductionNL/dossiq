# Proposal: compose-from-message-template

## Why

DqBerichtVersturen draws the Berichtenbox letter already written: a subject ("Uw Woo-verzoek 2026-0082: we beoordelen
de stukken") and a body addressed to the requester. The case type keeps its messages as email templates, and the
backend already fills one for a case (`POST /api/cases/{caseId}/email-templates/{templateId}/draft`), but no place
where a handler writes a message offered them: every letter started empty.

## What changes

- A `MessageTemplateSelect` component lists the active templates of the case's case type, optionally only those for
  given channels (a template without a channel fits every channel), and on a choice asks the backend for the draft of
  that template for this case and emits its subject and body.
- The Berichtenbox compose dialog shows it above the subject when the case type has templates for the Berichtenbox
  (`mailbox`, `mailbox-then-email`). Choosing one fills Subject and Message; both stay editable, and choosing another
  replaces them. Without templates nothing is shown.
- Nothing about a procedure is named in code: which messages exist, and for which channel, is case type configuration
  (`emailTemplate.channel` from case-type-messages-moment-channel).
