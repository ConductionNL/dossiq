# contact-channel-letter Specification

## Purpose
What dossiq does for contact channel letter. The requirements were first written by the change `contact-channel-letter` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-contact-channel-letter/`) and cover: a contact moment can be a letter.

## Requirements

### Requirement: a contact moment can be a letter

The contact moment's channel MUST accept `brief` (a paper letter), labelled "Letter" ("Brief" in Dutch), wherever channels are offered, counted or shown: logging a contact, the case's conversation chips with their counts (0 included), and the contact-moments table.

#### Scenario: DqZaakContact
- GIVEN a case with no letter logged yet
- THEN the conversation's chips include "Brief 0"
