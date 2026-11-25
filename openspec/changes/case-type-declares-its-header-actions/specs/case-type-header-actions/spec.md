# case-type-header-actions Delta: case-type-declares-its-header-actions

## ADDED Requirements

### Requirement: A case type declares the header row of its cases

A case type SHALL be able to declare, in its own configuration, which of the case page's declared header actions lead its cases' header (`headerActions`, in order) and which one is the primary button (`primaryAction`). The case page SHALL draw that row for every case of the type. A case type that declares neither SHALL keep the page's default row. An action the case type names but the page does not declare, or whose own visibility condition fails for the reader, SHALL be left out; the declaration SHALL never make an action available to a reader the action itself refuses.

#### Scenario: A declared row leads the header

- **GIVEN** a case type whose configuration declares `headerActions` ["schedule-hearing", "edit"] and `primaryAction` "record-finding"
- **AND** a case of that type the reader may edit
- **WHEN** the reader opens the case
- **THEN** the header SHALL show Schedule hearing, then Edit, then Record finding as the primary button
- **AND** the other header actions SHALL stay under More

#### Scenario: Nothing declared keeps the default row

- **GIVEN** a case type that declares no `headerActions`
- **WHEN** the reader opens a case of that type
- **THEN** the header SHALL show the page's default row (Message, Document, Record contact, Edit, More)

#### Scenario: The declaration cannot widen who may act

- **GIVEN** a case type that names "record-finding" as its primary action
- **AND** a reader for whom that action's own visibility condition fails
- **WHEN** the reader opens a case of that type
- **THEN** the header SHALL NOT show Record finding

### Requirement: The shipped complaint and grant types declare the boards' rows

The complaint case type and the grant case type in the shipped register SHALL declare the header rows boards DqKlachten and DqSubsidie draw, as data in the register seed.

#### Scenario: A complaint shows the board's row

- **GIVEN** the shipped complaint case type
- **WHEN** a handler opens a complaint
- **THEN** the header SHALL show Schedule hearing and Edit, with Record finding as the primary button

#### Scenario: A grant shows the board's row

- **GIVEN** the shipped grant case type
- **WHEN** a handler opens a grant case
- **THEN** the header SHALL show Pay advance, Amend decision and Edit, with Adopt as the primary button
