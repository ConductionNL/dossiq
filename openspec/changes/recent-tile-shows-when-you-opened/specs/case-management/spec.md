## MODIFIED Requirements

### Requirement: Favourites and recently opened are lenses and tiles (REQ-FAV-02)

The Cases index SHALL offer a Favourites chip and a Recently opened chip, each
narrowing the list through OpenRegister's own lens rather than a dossiq query.
The Dashboard SHALL carry a Favourites tile and a Recently opened tile over
the same two lenses, each linking through to the matching chip. The Recently
opened tile SHALL show, per case, when the reader last opened it, as a
relative date read from OpenRegister's `@self.viewedAt`.

**Feature tier**: MVP

#### Scenario: The Favourites chip lists only what you starred
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** two of five cases starred by you
- **WHEN** you pick the Favourites chip on Cases
- **THEN** the list SHALL hold those two cases and no others

#### Scenario: The Recently opened chip leads with the last case you read
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** three cases you opened, the last of them case C
- **WHEN** you pick the Recently opened chip on Cases
- **THEN** case C SHALL be the first row

#### Scenario: The dashboard tiles show the same two lists
@e2e tests/e2e/case-number-and-favourites.spec.ts

- **GIVEN** a starred case and a case you opened
- **WHEN** you open the Dashboard
- **THEN** the Favourites tile SHALL name the starred case
- **AND** the Recently opened tile SHALL name the case you opened

#### Scenario: The Recently opened tile says when you opened each case
@e2e exclude the moment comes from OpenRegister's read history, which the e2e instance does not log yet; the column binding and formatter are asserted in tests/vitest/caseFavourite.spec.js

- **GIVEN** a case you opened today and a case you opened three days ago
- **WHEN** you open the Dashboard
- **THEN** each row of the Recently opened tile SHALL show the relative date of your last opening, read from `@self.viewedAt`
- **AND** the case opened today SHALL read "Today"

#### Scenario: The Recently opened tile explains why it is empty when reads are not logged
@e2e exclude depends on the instance's audit trail setting; the empty text is asserted in tests/vitest/caseFavourite.spec.js

- **GIVEN** an instance whose audit trail is off, so it does not log who opens a case
- **WHEN** you open the Dashboard
- **THEN** the Recently opened tile SHALL hold no rows
- **AND** it SHALL say that it stays empty when the server does not log case views
