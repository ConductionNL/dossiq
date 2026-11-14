# case-dashboard-view Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-location-dialog-board-look](../../)

## ADDED Requirements

### Requirement: The Add location dialog draws the board

The Add location dialog SHALL open at the wizard width, name its address field once, set the map beside a 200px layer
list, and draw the BAG and cadastre cards 12px rounded with a 17px title at 700 over rows 8px apart.

#### Scenario: DqZaakLocatie

- **WHEN** a handler opens Add location on a case
- **THEN** the dialog is 720px wide
- **AND** the BAG card is half the content width with "BAG" at 17px
