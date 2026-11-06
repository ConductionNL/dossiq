# conversation-channel-icons Specification

## Purpose
What dossiq does for conversation channel icons. The requirements were first written by the change `conversation-channel-icons` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-conversation-channel-icons/`) and cover: The conversation chips carry the channel icons.

## Requirements

### Requirement: The conversation chips carry the channel icons

The case page's resident thread SHALL name an icon for every contact channel, the same one the contact-moments table shows.

#### Scenario: a phone message
- GIVEN a message on channel phone
- THEN its chip shows the phone icon before "Telefoon"
