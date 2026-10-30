## ADDED Requirements

### Requirement: The seats card reads as the board

The case's Zetels card SHALL show the subtitle "Wie het werk doet en wie ervoor staat", one column of pairs, and "Nee" /
"Niemand" for a hand-over that is not pending.

#### Scenario: no hand-over
- **GIVEN** a case with a handler and no hand-over
- **THEN** the card reads "Wacht op oppakken: Nee" and "Doorgegeven aan: Niemand"
