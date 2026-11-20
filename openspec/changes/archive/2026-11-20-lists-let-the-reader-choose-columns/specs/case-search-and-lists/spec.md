# case-search-and-lists Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [lists-let-the-reader-choose-columns](../../)

## ADDED Requirements

### Requirement: A reader chooses which columns a list shows (REQ-CSL-COL-01)

The Cases and Tasks lists SHALL offer a Columns tab in their side panel. Each column of the list SHALL be listed there
with a switch, and turning a switch off SHALL remove that column from the list without leaving the page.

#### Scenario: A column is turned off and on again

- **GIVEN** a handler on the Cases list, which shows the column Type
- **WHEN** they open the side panel, choose Columns and turn Type off
- **THEN** the list SHALL no longer show the Type column
- **AND** turning Type on again SHALL bring the column back
