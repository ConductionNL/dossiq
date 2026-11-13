# case-roles-role-line-and-party-kinds Specification

## Purpose
Each people card on the case page reads the field meant for it: the parties card shows the person or organisation, the roles card shows the role line.

## Requirements

### Requirement: Each people card reads the field meant for it

The Rollen card of a case SHALL print each party's role record description as its note, and the Partijen card SHALL print the party link's note led by the word for the person's kind: "Contact" for a person or contact, "Organisatie" for an organisation, "Collega" for a colleague or Nextcloud user.

#### Scenario: DqZaakBetrokkenen
- GIVEN J. van Wijk's link note reads "Machtiging namens Aannemersbedrijf Van Wijk B.V. · Awb 2:1" and his role record's description "namens Aannemersbedrijf Van Wijk B.V. · Awb 2:1"
- AND Sanne de Vries is a person whose link note reads "Lindelaan 14 · ingelogd met DigiD"
- WHEN the Betrokkenen tab opens
- THEN Rollen reads "Gemachtigde namens Aannemersbedrijf Van Wijk B.V. · Awb 2:1" for J. van Wijk
- AND Partijen reads "Contact · Lindelaan 14 · ingelogd met DigiD" for Sanne
