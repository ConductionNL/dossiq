# board-live-conversation-row Specification

## Purpose
How the case page lists a live conversation held from the case: one row per conversation with its subject, meta line and status badge.

## Requirements

### Requirement: A live conversation is one row beside its badge

In the board look the case's live conversation list SHALL draw each conversation as one row 10px above and below its
text, the subject and meta line on the normal line height, the meta line at 13px, and the status badge beside them,
centred on both. The footnote SHALL sit 12px under the list. Nextcloud's own look SHALL keep the stacked rows.

#### Scenario: DqZaakContact "Live gesprek"
- GIVEN a case with one ended conversation
- WHEN the Contact tab shows "Live gesprek"
- THEN the conversation row is 56px tall with "Afgelopen" beside its two lines and the card is 177px tall
