# party-pages Delta

**Status**: proposed (design)
**Scope**: dossiq
**OpenSpec changes**:

- [organisation-contact-persons](../../)

## ADDED Requirements

### Requirement: An organisation names its contact persons and its main contact

An organisation SHALL be able to hold person parties in a contact role (Voorzitter, Secretaris, Penningmeester,
Contactpersoon), exactly one of them as its main contact. The organisation page SHALL show the main contact with
their role and correspondence address, and a link to all contact persons with their count.

#### Scenario: DqOrganisatie

- **GIVEN** Buurtvereniging Oost with Marja Verbeek as Voorzitter (main contact) and one more contact person
- **THEN** the side card "Hoofdcontact" reads "MV Marja Verbeek, Voorzitter", her e-mail address, and "Alle 2 contactpersonen"
