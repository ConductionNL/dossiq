## ADDED Requirements

### Requirement: A case opens beside its list, which keeps its position (REQ-CPL-01)

`#Cases` and `#Queue` SHALL declare that a case opens beside the list. The
list SHALL keep its scroll position and its selection while a case is
open. No other dossiq page SHALL declare it. dossiq SHALL ship no split
view component.

#### Scenario: the queue does not scroll back to the top
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a handler scrolled to the fortieth case in the queue
- **WHEN** they open that case
- **THEN** the list SHALL still be at the fortieth case
- **AND** the case SHALL be readable beside it

#### Scenario: closing the case leaves the list where it was
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a case open beside a scrolled list
- **WHEN** the handler closes it
- **THEN** the list SHALL be unchanged

#### Scenario: a page nobody triages from does not declare it
@e2e exclude a manifest key no page carries cannot be observed in a browser; asserted in tests/vitest/caseListPlace.spec.js, "declares it on no page anybody merely browses"

- **GIVEN** the case types page
- **WHEN** its manifest is read
- **THEN** it SHALL NOT declare a side-by-side case

### Requirement: Next and previous walk the list the handler came from (REQ-CPL-02)

`#Cases` and `#Queue` SHALL declare next and previous within the list. The
step SHALL follow the filters and the ordering that were applied, and
SHALL NOT step through an unfiltered list.

#### Scenario: a handler walks a filtered queue
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a queue filtered to one case type
- **WHEN** a handler steps to the next case
- **THEN** the next case SHALL be of that case type

#### Scenario: the last case has no next
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** the last case of a filtered list
- **WHEN** the handler looks for the next
- **THEN** no next SHALL be offered

### Requirement: A reference shows its summary in place (REQ-CPL-03)

`#CaseDetail` SHALL declare that a reference to another case or to a
catalogued object offers a summary without navigation. The summary SHALL
show only what the reader may see.

#### Scenario: a related case is read without leaving
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a case referencing another case
- **WHEN** a handler asks for the reference's summary
- **THEN** the summary SHALL appear without navigating away

#### Scenario: a summary respects what the reader may see
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a reference to a case the reader may not open
- **WHEN** the summary is requested
- **THEN** it SHALL NOT disclose that case's content

### Requirement: Each person sets their own interface options in one place (REQ-CPL-04)

dossiq SHALL declare its per-person options in `src/personalSettings.js`,
so they sit in the Nextcloud personal settings a user already uses. dossiq
SHALL NOT offer a second place to set a personal preference.

#### Scenario: a handler finds dossiq's options where every other app's are
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a signed-in handler
- **WHEN** they open their personal settings
- **THEN** dossiq's options SHALL be there

#### Scenario: there is no second settings screen
@e2e exclude the absence of a screen is a property of the tree, not of a page; asserted in tests/vitest/personalSettings.spec.js, "offers no second place to set a per-person preference"

- **GIVEN** the dossiq tree
- **WHEN** it is read for a per-person preference screen of its own
- **THEN** none SHALL exist

### Requirement: A handler may hold their own order on a case list (REQ-CPL-05)

`#Cases` SHALL declare that a handler may place cases in an order of their
own. The held order SHALL be that person's alone, SHALL NOT change what
anyone else sees, and SHALL NOT be the default. A list with no held order
SHALL sort as it does today.

#### Scenario: one person's order is not everyone's
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a handler who has ordered a list by hand
- **WHEN** a colleague opens the same list
- **THEN** the colleague SHALL see the unchanged order

#### Scenario: a fresh list sorts as it does today
@e2e tests/e2e/case-page-and-list-as-a-place.spec.ts

- **GIVEN** a handler with no held order
- **WHEN** they open the case list
- **THEN** it SHALL sort as it does today
