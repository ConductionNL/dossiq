# example-set-omgevingsvergunning-regulier

## ADDED Requirements

### Requirement: A child case type derives from Omgevingsvergunning

The Zuiddrecht example set SHALL hold the case type "Omgevingsvergunning regulier" as a draft version 5 whose parent is Omgevingsvergunning, with one required field of its own.

#### Scenario: The case type's fields
- **GIVEN** the set is installed
- **WHEN** an administrator opens Omgevingsvergunning regulier's fields
- **THEN** the parent's fields SHALL show as inherited and Situatietekening as its own required field
