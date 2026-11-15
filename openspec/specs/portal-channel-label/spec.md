# portal-channel-label Specification

## Purpose
What dossiq does for portal channel label. The requirements were first written by the change `portal-channel-label` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-portal-channel-label/`) and cover: the portal channel is named after the portal.

## Requirements

### Requirement: the portal channel is named after the portal

Wherever a contact moment's channel is named (the case's conversation chips, the contact-moments table, Log contact), the `webformulier` channel MUST read the portal's name from the app config, and "Web form" ("Webformulier") when no portal name is set.

#### Scenario: Zuiddrecht
- GIVEN portaliq's portal name "Mijn Zuiddrecht"
- THEN the chip reads "Mijn Zuiddrecht 3" and the table's channel reads "Mijn Zuiddrecht"
