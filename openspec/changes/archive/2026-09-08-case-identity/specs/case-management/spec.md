## ADDED Requirements

### Requirement: Every new case gets a number (REQ-CM-25)

Every new case gets a number like 2026-0042 without you typing it. The `case`
schema SHALL declare `identifier` as a generated value: the year of the start
date, a hyphen and a four-digit sequence that restarts every year. The field
SHALL be read-only on every form and SHALL NOT appear on the New case form. A
case that already holds an identifier SHALL keep it.

**Feature tier**: MVP

#### Scenario: A case filed from the form gets the next number
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** the last case of this year is numbered 2026-0041
- **WHEN** you file a case from New case on the Dashboard
- **THEN** the case page SHALL show the number 2026-0042
- **AND** the New case form SHALL NOT have offered a number field

#### Scenario: A case posted to the API gets a number too
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** a case posted to the case endpoint without an identifier
- **WHEN** the answer arrives
- **THEN** its identifier SHALL match `YYYY-NNNN`
- **AND** the year SHALL be the year of its start date

#### Scenario: An existing number stays
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** a case that holds the identifier BZW-2025-17
- **WHEN** you edit and save its title
- **THEN** its identifier SHALL still be BZW-2025-17

### Requirement: You tag a case and filter on tags (REQ-CM-26)

You tag a case and filter the list on tags. The `case` schema SHALL carry
`tags`, a list of free words. `CaseDetail` SHALL show the tags in a sidebar
tab where you add and remove them. The Cases index SHALL offer a Tags filter
that lists cases carrying the chosen tag.

**Feature tier**: MVP

#### Scenario: You add a tag on the case
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** a case without tags
- **WHEN** you open the Tags tab and add spoed
- **THEN** the tag SHALL show on the case after a reload

#### Scenario: You filter the list on a tag
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** two cases tagged wijk-noord and ten without
- **WHEN** you filter the Cases index on wijk-noord
- **THEN** the list SHALL hold the two tagged cases only

### Requirement: The case shows its lead time, archive and payment data (REQ-CM-27)

You read the case's legal lead time, archive nomination and destruction date
on the case. `CaseDetail` SHALL show a Terms and archive block with the type's
processing deadline, the case's legal basis, archive nomination, archive
action date, archive status, payment indication and last payment date. The
`case` schema SHALL carry `legalBasis` as text.

**Feature tier**: MVP

#### Scenario: The block reads the type and the case
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** a case of a type with a processing deadline of 8 weeks
- **AND** the case carries archive nomination blijvend bewaren and legal basis Awb 4:13
- **WHEN** you open the case page
- **THEN** Terms and archive SHALL show 8 weeks as the statutory lead time
- **AND** SHALL show blijvend bewaren and Awb 4:13

#### Scenario: Empty fields stay visible
@e2e tests/e2e/case-identity.spec.ts

- **GIVEN** a case with no archive action date
- **WHEN** you open the case page
- **THEN** the Terms and archive block SHALL show the archive action date row as empty, not hide it
