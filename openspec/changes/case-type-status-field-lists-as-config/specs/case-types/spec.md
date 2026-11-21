# case-types Delta: case-type-status-field-lists-as-config

**Status**: in-progress
**Scope**: dossiq

## MODIFIED Requirements

### Requirement: A case type's statuses and fields are edited on its page

The case type page SHALL edit its statuses and its own fields through object lists declared in the manifest. A status reorder SHALL write only `order`. A status or field edit SHALL write only the keys it changed. A status's order SHALL be unique among the type's statuses. Every form field SHALL be bound to a property its schema declares, or be a field that is not stored.

#### Scenario: Renaming a status keeps its planning keys

- **GIVEN** a status with capacity 5 and phaseTermDays 10
- **WHEN** the reader renames it on the case type page and saves
- **THEN** the status keeps capacity 5 and phaseTermDays 10

#### Scenario: A choice list needs choices or a scheme

- **GIVEN** a field with "Choice list" on, no choices and no concept scheme
- **WHEN** the reader saves
- **THEN** the form asks for the choices
