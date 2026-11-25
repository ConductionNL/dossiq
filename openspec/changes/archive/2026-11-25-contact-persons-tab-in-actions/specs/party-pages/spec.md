# party-pages Delta

**Status**: superseded by organisation-contact-persons-tab
**Scope**: dossiq
**OpenSpec changes**:

- [contact-persons-tab-in-actions](../../)

## ADDED Requirements

### Requirement: An organisation's contact persons open from the Actions menu and the main contact card

The organisation page SHALL show the board's five tabs in its strip, and SHALL offer the contact persons through
the strip's Actions menu and through the main contact card's link.

#### Scenario: DqOrganisatie

- **GIVEN** Buurtvereniging Oost with two contact persons
- **THEN** the strip reads Overzicht, Zaken, Contactmomenten, Bestanden, Historie on one line
- **AND** "Alle 2 contactpersonen" opens the Contact persons tab
