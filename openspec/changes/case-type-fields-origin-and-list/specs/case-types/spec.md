# case-types Delta

## ADDED Requirements

### Requirement: The Velden table shows inherited fields and where each comes from

The Velden table SHALL list a case type's own fields (editable) followed by the fields it inherits (read only), with a
Herkomst column ("Eigen veld" or "Geërfd van {type}") and an In de lijst column saying whether the case list of this
case type shows the field.

#### Scenario: A regulier permit inheriting from Omgevingsvergunning

- **GIVEN** a case type with its own field Bouwkosten, shown in its case list, and the inherited field Locatie
- **THEN** Bouwkosten reads Eigen veld · Ja and Locatie reads Geërfd van Omgevingsvergunning, without row actions
