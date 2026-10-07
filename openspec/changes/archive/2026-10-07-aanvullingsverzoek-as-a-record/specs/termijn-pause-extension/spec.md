## ADDED Requirements

### Requirement: A request to complete an application is a record on the case (REQ-AVR-01)

A request to an applicant to complete their submission SHALL be written as
an `aanvullingsverzoek` object on the case. It SHALL name the party asked,
the `pauseReason` that types it, the items that are missing, who asked, when
they asked and the date the hersteltermijn ends. A suspended term SHALL NOT
stand in for the record.

#### Scenario: Asking writes the request and suspends the term
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** a case in behandeling with a running beslistermijn
- **WHEN** a handler asks the applicant for two missing documents
- **THEN** an `aanvullingsverzoek` SHALL be written naming both items, the reason and the hersteltermijn date
- **AND** the term SHALL be suspended through the engine timer, not by a second clock

#### Scenario: The request names who asked
@e2e exclude unit; AanvullingsverzoekServiceTest

- **GIVEN** a request written by a named handler
- **WHEN** the record is read a year later
- **THEN** it SHALL still name the handler, the moment and the reason

### Requirement: The answer says which items arrived (REQ-AVR-02)

Recording an answer SHALL mark each requested item as received or still
missing, SHALL close the request only when the handler says it is complete,
and SHALL resume the term through the existing credit path. A partially
answered request SHALL stay open with its outstanding items named.

#### Scenario: A partial answer leaves the request open
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** an open request for two items
- **WHEN** one of them arrives and the handler records it
- **THEN** the request SHALL stay open naming the item still missing
- **AND** the term SHALL stay suspended

#### Scenario: A full answer closes the request and resumes the clock
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** an open request for two items
- **WHEN** both arrive and the handler records the answer as complete
- **THEN** the request SHALL read answered
- **AND** the unused part of the suspension SHALL be credited back as it is today

### Requirement: An unanswered request expires and stays readable (REQ-AVR-03)

A request whose hersteltermijn passes without an answer SHALL become
`expired`. It SHALL remain readable with its items and its dates unchanged,
and nothing SHALL delete or rewrite it.

#### Scenario: The hersteltermijn passes
@e2e exclude time-dependent; unit over the timer-fired listener, AanvullingsverzoekExpiryTest

- **GIVEN** an open request whose hersteltermijn is today
- **WHEN** the day passes without an answer
- **THEN** the request SHALL read expired
- **AND** the items it asked for SHALL still be readable

### Requirement: Cases waiting on an applicant are a list (REQ-AVR-04)

A case with an open `aanvullingsverzoek` SHALL be findable as such. The work
list SHALL filter on it and SHALL show how long the request has been open. A
count of cases waiting on an applicant SHALL come from the requests
themselves.

#### Scenario: The work list answers what we are waiting on
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** three cases with an open request and twelve without
- **WHEN** a handler filters the work list on waiting for the applicant
- **THEN** exactly the three SHALL be listed
- **AND** each SHALL show the days its request has been open

#### Scenario: Answering removes the case from the filter
@e2e tests/e2e/aanvullingsverzoek-as-a-record.spec.ts

- **GIVEN** a case in that filter
- **WHEN** its request is answered in full
- **THEN** the case SHALL leave the filter
