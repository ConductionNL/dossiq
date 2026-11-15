## ADDED Requirements

### Requirement: The Roles rows read as the board draws them

The Roles section of a case SHALL name the primary party "Primaire partij", SHALL show "Geen geheimhouding" on the primary person only when it carries no geheimhouding indicator, and SHALL draw an indicator as "Indicator: {label}".

#### Scenario: DqZaakBetrokkenen
- GIVEN Sanne de Vries is the primary party and J. van Wijk her representative, neither with secrecy, and Bewonersvereniging Lindelaan carries "Meerdere meldingen"
- WHEN the Betrokkenen tab opens
- THEN Sanne's row reads "Primaire partij · verzoeker" and "Geen geheimhouding", J. van Wijk's row has no secrecy pill, and the reporter's row ends with "Indicator: Meerdere meldingen"
