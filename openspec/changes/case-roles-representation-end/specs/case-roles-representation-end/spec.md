## ADDED Requirements

### Requirement: A representative on a case shows when the representation ends

The Roles section of a case SHALL show, on the row of a party that acts for another party and whose link has an end date, a warning pill "Machtiging tot {date}" (English "Authorisation until {date}").

#### Scenario: DqZaakBetrokkenen
- GIVEN J. van Wijk is the authorised representative of Aannemersbedrijf Van Wijk B.V. until 31 December
- WHEN the Betrokkenen tab opens
- THEN his row in Rollen ends with "Machtiging tot 31 dec"
