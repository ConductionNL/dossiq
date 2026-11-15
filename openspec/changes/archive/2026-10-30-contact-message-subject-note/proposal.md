# A contact moment carries its subject, note and attachment count

## Why

DqZaakContact draws an e-mail with "Onderwerp: Aanvulling op Woo-verzoek 2026-0110" above its text, and a grey line under some messages: "Samengevat door Pieter Jansen, verzoek samen opgenomen", "Ontvangstbevestiging, automatisch verstuurd", "Binnengekomen op het Woo-postvak, 1 bijlage". The contactmoment schema had only `summary`, so the conversation could show none of it (DQ-DATA, pre-r32).

## What changes

- contactmoment 1.7.0 (both register files): optional `subject` (string), `note` (string), both read-restricted like `summary`, and `attachmentCount` (integer, minimum 0).
- The resident thread maps them: `subjectField: subject`, `noteField: note`, `attachmentCountField: attachmentCount`. Needs nextcloud-vue feat/conversation-subject-attachments for the subject line and the count; the note line already renders.
