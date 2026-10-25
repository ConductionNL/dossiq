# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [contact-side-cards-from-moments](../../)

## ADDED Requirements

### Requirement: A contact's side cards read the newest contact moment

The Identity side card of a contact page SHALL say how the person was last identified at a contact moment and on
which date, and the Reachable via card SHALL say when and through which channel the last contact was. The entries
about the person SHALL show whether or not the person has contact moments.

#### Scenario: DqContact

- **GIVEN** Sanne de Vries reacted through Mijn Zuiddrecht, logged in with DigiD, on 4 October
- **THEN** Identiteit opens with "Ingelogd met DigiD" and "bij het contactmoment van 4 okt"
- **AND** it shows BSN, Geheimhouding and "Bron: Basisregistratie personen" as rows
- **AND** Bereikbaar via reads "Laatst contact op 4 okt, via webformulier"

#### Scenario: A person without contact moments

- **GIVEN** a person with no contact moments
- **THEN** Identiteit shows BSN, Geheimhouding and Bron without the identification lead
- **AND** Bereikbaar via shows only "Telefoon en e-mail staan niet in de basisregistratie."
