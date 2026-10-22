# case-roles-no-secrecy-line

## ADDED Requirements

### Requirement: A person on a case says whether it has secrecy

The Roles section of a case SHALL show "Geen geheimhouding" for a person party whose record carries no `geheimhouding` indicator. A person who does carry it SHALL show that indicator with its verdict instead.

#### Scenario: Sanne on her Woo case
- **GIVEN** Sanne de Vries is the primary party of the Lindelaan Woo case and asked for no secrecy
- **WHEN** a handler opens the case's people
- **THEN** her row SHALL read "Geen geheimhouding"
