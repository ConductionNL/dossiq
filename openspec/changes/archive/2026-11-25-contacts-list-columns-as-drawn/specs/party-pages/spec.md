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

### Requirement: A longer address under a resident's name wraps rather than being cut

The contacts list's name column SHALL wrap (`cellClass: "cn-cell--wrap"`), so an address longer than the column reads in full over two lines instead of ending in an ellipsis. The avatar widget wraps its secondary line in such a cell from nextcloud-vue `avatar-cell-wraps` on.

#### Scenario: Ans Bos on Oosthavenweg

@e2e exclude {column config, asserted in tests/vitest/contactsColumnWidths.spec.js; the wrap itself is nextcloud-vue tests/css/avatarCellWraps.spec.js}

- **GIVEN** Ans Bos, Oosthavenweg 12, Zuiddrecht, in a 250px name column
- **THEN** her row reads "Oosthavenweg 12, Zuiddrecht" in full
