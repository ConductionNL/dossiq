# case-types Delta

## ADDED Requirements

### Requirement: The Versies card says each version's state and offers the next step

The case type's side Versies card SHALL label a draft "Concept", a version closed for new cases "Vastgezet" and
any other version "Actief". On a published version it SHALL end with "Een gepubliceerde versie verandert niet
meer. Wijzigen maakt een nieuwe versie." above "Nieuwe versie maken" across the card, which opens the new version
dialog; on a draft it SHALL end with "Publiceren".

#### Scenario: Version 4 of Omgevingsvergunning regulier, published

- **GIVEN** versions 4 (in force), 3 and 2 (closed)
- **THEN** the card reads Actief, Vastgezet, Vastgezet and ends with the note and "Nieuwe versie maken"

#### Scenario: Version 5, a draft

- **THEN** the card reads Concept for version 5 and ends with "Publiceren"
