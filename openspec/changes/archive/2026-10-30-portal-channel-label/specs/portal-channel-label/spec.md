## ADDED Requirements

### Requirement: the portal channel is named after the portal

Wherever a contact moment's channel is named (the case's conversation chips, the contact-moments table, Log contact), the `webformulier` channel MUST read the portal's name from the app config, and "Web form" ("Webformulier") when no portal name is set.

#### Scenario: Zuiddrecht
- GIVEN portaliq's portal name "Mijn Zuiddrecht"
- THEN the chip reads "Mijn Zuiddrecht 3" and the table's channel reads "Mijn Zuiddrecht"
