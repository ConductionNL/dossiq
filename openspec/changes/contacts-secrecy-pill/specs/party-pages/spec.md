# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [contacts-secrecy-pill](../../)

## ADDED Requirements

### Requirement: The contacts list marks a protected resident beside the BSN

The contacts list SHALL show a "Geheimhouding" pill beside the BSN of a resident whose data is protected, and none for
other residents.

#### Scenario: DqContacten

- **GIVEN** Bram Smit with data protection
- **THEN** his row reads "*****4054 Geheimhouding" in the BSN column
