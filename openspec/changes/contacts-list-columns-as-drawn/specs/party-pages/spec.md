# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [contacts-list-columns-as-drawn](../../)

## ADDED Requirements

### Requirement: The contacts list sizes its columns as the board

The contacts list SHALL give its name column room for the resident's address under the name, size BSN and place of
residence to their values, leave the rest of the width to the description, and let the description sort.

#### Scenario: DqContacten

- **GIVEN** Henk Bakker, Kerkplein 3, Zuiddrecht
- **THEN** his row reads "Kerkplein 3, Zuiddrecht" in full under his name
