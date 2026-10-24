# case-types Delta

## ADDED Requirements

### Requirement: The Velden table describes each field in words

The Velden table on a case type's Fields tab SHALL read Veld, Soort and Invullen: the field's name, the kind of
answer in words and whether it is required, optional or read only.

#### Scenario: A choice list

- **GIVEN** a field "Soort bouwwerk" with six options, required
- **THEN** its row reads Soort bouwwerk · Keuzelijst, 6 opties · Verplicht
