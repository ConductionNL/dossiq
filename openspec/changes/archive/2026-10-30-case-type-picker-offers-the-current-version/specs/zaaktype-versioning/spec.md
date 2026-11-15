## ADDED Requirements

### Requirement: The New case picker declares the current-version rule (REQ-ZV-14)

The `case.caseType` property SHALL scope its picker through
`x-relation-filter` to case types that are not drafts and carry no
`supersededBy`, so the New case form offers only the current version of a
versioned case type, whatever its name.

#### Scenario: Evenementenvergunning is offered once
@e2e exclude the picker's options are a query the schema declares; the declaration is pinned in tests/vitest/newCaseCaseTypePicker.spec.js and the query was checked live on review-check (25 of 26 case types, v1 left out)

- **GIVEN** Evenementenvergunning version 1 carrying `supersededBy` naming version 2
- **WHEN** somebody opens the New case form
- **THEN** the case type picker SHALL offer version 2
- **AND** it SHALL NOT offer version 1
