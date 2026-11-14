## MODIFIED Requirements

### Requirement: Case detail shows the initiator

You see who asked for the case at the top of the case page. The `initiator`
widget on `CaseDetail` SHALL render a person card in the first row of the
layout, beside `case-core`, when the projection fields are set. The card
SHALL show `initiatorDisplayName`, the type (Person, Company or Contact), the
identifying `initiatorSourceId` as a link to the source record (the
`brpPerson` or `kvkCompany` register object, or the contact), and the address
resolved from the source row. When `requester` is set and the projection is
empty, the card SHALL resolve the row by uuid and fill the projection. Cases
without a requester SHALL show no empty card.

#### Scenario: Initiator visible on the case
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case with `initiatorType: person` and a seeded persona as requester
- **WHEN** a handler opens the case page
- **THEN** the first row SHALL hold a card with the persona's name, the type Person, the BSN and the address
- **AND** the BSN SHALL link to the seeded `brpPerson` record

#### Scenario: A company card links to the KvK record
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case with `initiatorType: company` and `initiatorSourceId: 69599084`
- **WHEN** a handler opens the case page
- **THEN** the card SHALL show the company's display name, the type Company and the KvK number
- **AND** the KvK number SHALL link to the seeded `kvkCompany` record

#### Scenario: No initiator, no clutter
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case without a requester
- **WHEN** a handler opens the case page
- **THEN** no initiator card SHALL render

## ADDED Requirements

### Requirement: The case list names the requester (REQ-ID-2)

You see who asked for each case in the list and filter on their name. Page
`Cases` SHALL render a Requester column over `initiatorDisplayName` after
`title`, and its sidebar SHALL offer a text filter on the same field. The
column reads the projection until `CnIndexPage` renders a label field for a
`$ref` column; the data does not change when that lands.

#### Scenario: The requester is a column
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a seeded case whose requester is a seeded persona
- **WHEN** a handler opens Cases in table view
- **THEN** the row SHALL show the persona's name in the Requester column

#### Scenario: The list filters on the requester's name
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** two seeded cases with different requesters
- **WHEN** the handler types the first requester's surname into the Requester filter
- **THEN** the list SHALL show the first case and not the second

### Requirement: A protected person's number stays masked until you reveal it (REQ-ID-3)

You see when a person's data is protected, and the BSN stays hidden until you
ask for it. When the source `brpPerson` row carries `indicatieGeheim: true`,
the card SHALL show a Protected marker and render the BSN as five dots plus
the last four digits. A Reveal button SHALL fetch the `brpPerson` row through
OpenRegister with `_reason: "bsn-reveal"` and then show the full number.
OpenRegister SHALL log that read, attributed to the case's processing
activity; dossiq SHALL write no log row itself. A person without the flag
SHALL render the BSN in full.

#### Scenario: The BSN is masked
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case whose requester is the seeded protected persona
- **WHEN** a handler opens the case page
- **THEN** the card SHALL show the Protected marker
- **AND** the BSN SHALL read as five dots followed by its last four digits

#### Scenario: A reveal shows the number and is a logged read
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** the same case
- **WHEN** the handler presses Reveal
- **THEN** the card SHALL show the full BSN
- **AND** the page SHALL make one read of the `brpPerson` row carrying `_reason=bsn-reveal`

#### Scenario: An unprotected person is not masked
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case whose requester is a seeded persona without `indicatieGeheim`
- **WHEN** a handler opens the case page
- **THEN** the card SHALL show the full BSN and no Protected marker
