# case-types Delta

**Status**: proposed
**OpenSpec changes**:

- [status-type-board-colours](../../)

## ADDED Requirements

### Requirement: A shipped status takes the colour its boards draw

Every status dossiq ships SHALL name the colour the palette gives its name. An upgrade SHALL give a status dossiq seeded
that colour while the status holds no colour, grey, or the colour dossiq shipped it with, and SHALL leave any other
colour, and every status an administrator created, as it is.

#### Scenario: a case in progress

- **GIVEN** a case in the shipped status "In behandeling"
- **THEN** its status pill is purple

#### Scenario: a colour an administrator chose

- **GIVEN** a shipped status whose colour an administrator set to red
- **WHEN** dossiq upgrades
- **THEN** the status stays red
