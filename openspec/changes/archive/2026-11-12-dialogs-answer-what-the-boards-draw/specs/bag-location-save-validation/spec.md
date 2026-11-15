# bag-location-save-validation Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [dialogs-answer-what-the-boards-draw](../../)

## ADDED Requirements

### Requirement: A refused location save names why by code

A location save refused by the BAG check SHALL carry `code: bag-address-unknown` with the `addressId` when the BAG does
not know the address, and `code: bag-check-unavailable` when the check could not run while it is on. The location
schema the register declares (`case-location`) SHALL back `location_schema`, so the check runs.

#### Scenario: Unknown address

- **GIVEN** the BAG check is on
- **WHEN** a location is saved with a nummeraanduiding the BAG does not know
- **THEN** the save is refused with `bag-address-unknown` and that id
