## ADDED Requirements

### Requirement: REQ-CTF-01 A case type says which of its fields are worth filtering on

A `propertyDefinition` SHALL carry a `filterable` boolean. A definition that
declares nothing SHALL NOT be offered as a filter.

The declaration SHALL live on the definition rather than on the Cases page,
because the same answer has to serve the list, the API and the portal, and a
page config gives each of them its own.

#### Scenario: A definition that declares nothing is not offered

- **GIVEN** a case type with three property definitions, one of which
  declares `filterable: true`
- **WHEN** a handler narrows the Cases page to that case type
- **THEN** the filter bar SHALL offer that one definition
- **AND** the other two SHALL NOT appear

@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

### Requirement: REQ-CTF-02 The case list filters on a case type's own fields

When the Cases page has narrowed to one case type, it SHALL offer that case
type's filterable definitions as filters, and SHALL compile each filled
field to one `_related[caseProperty][case][…]` block over openregister's
related-row query.

Two filled fields SHALL produce two numbered blocks. They SHALL NOT be
merged into one block, because one block asks for a single `caseProperty`
row that is two property definitions, and no row is that, so the handler
would get an empty list and no reason for it.

The control offered per definition SHALL follow the definition's own
`propertyType`: a number is a range, a date is a date range, an enumeration
backed by `enumValues` is a select, and everything else is text.

#### Scenario: Two fields narrow to the cases that satisfy both

- **GIVEN** a case type with filterable definitions "construction cost" and
  "district", and three cases of that type
- **AND** one case costs 150000 in Noord, one costs 90000 in Noord, and one
  costs 150000 in Zuid
- **WHEN** a handler filters on cost over 100000 and district Noord
- **THEN** only the first case SHALL be listed

@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

#### Scenario: Clearing the case type clears its field filters

- **GIVEN** a handler has narrowed to one case type and filled two of its
  field filters
- **WHEN** the handler clears the case type
- **THEN** the field filters SHALL be cleared with it
- **AND** the list SHALL show every case type again

@e2e tests/e2e/case-type-fields-filter-the-case-list.spec.ts

### Requirement: REQ-CTF-03 A refused field filter says which field broke

A `_related` block openregister refuses SHALL be shown to the handler under
the filter bar, naming the field, and the list SHALL NOT be rendered as
empty.

An empty list and a refused query look the same on screen, and only one of
them is an answer.

#### Scenario: A refused filter is not an empty result

- **GIVEN** a filter openregister refuses
- **WHEN** the handler applies it
- **THEN** the page SHALL show the refusal and name the field
- **AND** SHALL NOT show the empty-result state

@e2e exclude Refusal shapes are openregister's contract; the dossiq half is asserted by vitest over a stubbed ApiError.

### Requirement: REQ-CTF-04 A whole-result act carries the field filters

`readListFilters()` SHALL carry `_related` into a whole-result bulk act
alongside `_search`.

A bulk act that drops the filter acts on every case in the register while
the screen said 43, which is the surprise the selection module exists to
prevent.

#### Scenario: Select all acts on the narrowed set

- **GIVEN** a filtered list of 43 cases out of 400
- **WHEN** the handler selects the whole result and starts a bulk act
- **THEN** the job SHALL receive 43 cases

@e2e exclude Asserted by vitest over `selectionScope`, mutation-checked; the bulk job itself is covered by `bulk-actions-report-progress`.
