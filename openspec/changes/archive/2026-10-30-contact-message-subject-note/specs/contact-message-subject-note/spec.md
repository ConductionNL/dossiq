## ADDED Requirements

### Requirement: A contact moment carries its subject, note and attachment count

The contactmoment schema SHALL offer optional `subject`, `note` and `attachmentCount` fields, and the case page's conversation SHALL show the subject above a message's summary and the note with the attachment count under it.

#### Scenario: an e-mail with an attachment
- GIVEN a contact moment with subject "Aanvulling op Woo-verzoek 2026-0110", note "Binnengekomen op het Woo-postvak" and attachmentCount 1
- THEN the conversation shows "Onderwerp: Aanvulling op Woo-verzoek 2026-0110" above the summary and "Binnengekomen op het Woo-postvak, 1 bijlage" under it
