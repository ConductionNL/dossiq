## ADDED Requirements

### Requirement: The case list shows the columns of the type you picked (REQ-CM-70)

You pick a case type in the sidebar and the list shows what that type needs.
`#Cases` SHALL let each `caseType` scope of its `folderSidebar` declare
`columns`, and SHALL render those columns while that scope is selected. A
scope that declares no `columns` SHALL inherit the page's columns, and the
All types folder SHALL always show the page's columns.

#### Scenario: A permit shows its expiry date
@e2e tests/e2e/columns-follow-the-case-type.spec.ts

- **GIVEN** the Cases page with a case type scope declaring the columns case
  number, title, decision date and expiry date
- **WHEN** you pick that case type in the folder sidebar
- **THEN** the list header SHALL show those four columns
- **AND** it SHALL NOT show the page's default columns

#### Scenario: All types returns to the page columns
@e2e tests/e2e/columns-follow-the-case-type.spec.ts

- **GIVEN** a case type scope with its own columns is selected
- **WHEN** you pick All types
- **THEN** the list header SHALL show the page's columns again

#### Scenario: A silent scope inherits
@e2e exclude Manifest resolution, covered by vitest.

- **GIVEN** a case type scope that declares no `columns`
- **WHEN** you pick it
- **THEN** the list SHALL show the page's columns

### Requirement: A scope may order and search on its own fields (REQ-CM-71)

A `caseType` scope MAY declare `sort` and `searchFields` beside its
`columns`. While that scope is selected the list SHALL order by the scope's
`sort` and SHALL search the scope's `searchFields`. Both SHALL fall back to
the page's when the scope is silent.

#### Scenario: A bezwaar orders by its hearing date
@e2e tests/e2e/columns-follow-the-case-type.spec.ts

- **GIVEN** a bezwaar scope declaring `sort` on the hearing date, descending
- **WHEN** you pick it
- **THEN** the first row SHALL be the case with the latest hearing date

### Requirement: A column names a property the case type carries (REQ-CM-72)

A scope column SHALL name a property declared on that case type. A manifest
declaring a column no case type property answers to SHALL fail the manifest
test, so an empty column cannot reach a page.

#### Scenario: An unknown column fails the test, not the page
@e2e exclude Manifest validation, covered by vitest.

- **GIVEN** a scope declaring a column named `vervaldatum` on a case type with
  no such property
- **WHEN** the manifest test runs
- **THEN** it SHALL fail naming the scope and the column
