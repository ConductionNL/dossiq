## ADDED Requirements

### Requirement: A contact moment names its case (REQ-KWZ-11)

Every contact moment logged from a case says which case it is about. The
`contactmoment` schema SHALL carry `case`, a reference to one `case` object,
beside the existing `relatedCases` list. When a contact moment is created
with `case` set and `relatedCases` empty, the service SHALL copy `case` into
`relatedCases`, so the KCC voorblad lists the contact as before.

**Feature tier**: MVP

#### Scenario: A contact logged on the case carries the case
@e2e tests/e2e/case-communication.spec.ts

- **GIVEN** a case
- **WHEN** you log a contact from the case page and save it
- **THEN** the saved contact moment SHALL hold the case's id in `case`
- **AND** `relatedCases` SHALL hold that same id

#### Scenario: A KCC write keeps its own list
@e2e exclude The KCC path posts without a page; ContactMomentServiceTest asserts the untouched list.

- **GIVEN** a contact moment posted to the API with two ids in `relatedCases` and no `case`
- **WHEN** the service saves it
- **THEN** `relatedCases` SHALL still hold the two ids
- **AND** `case` SHALL stay empty

### Requirement: The case page lists its contact moments (REQ-KWZ-12)

You read every call, visit and message on the case in one tab. `CaseDetail`
SHALL show a Communication tab in `case-panels` that lists the contact
moments whose `case` is the open case, newest first, with the columns date,
channel, direction and summary. A case without contact moments SHALL show
the tab with an empty state, not hide it.

**Feature tier**: MVP

#### Scenario: The tab lists the case's contacts
@e2e tests/e2e/case-communication.spec.ts

- **GIVEN** a case with an inbound phone contact and an outbound e-mail contact
- **AND** another case with one contact of its own
- **WHEN** you open the first case's Communication tab
- **THEN** the list SHALL show two rows with their date, channel, direction and summary
- **AND** SHALL NOT show the other case's contact

#### Scenario: Newest first
@e2e tests/e2e/case-communication.spec.ts

- **GIVEN** the same case
- **WHEN** you read the list
- **THEN** the contact with the later start time SHALL be the first row

#### Scenario: An empty case shows the tab
@e2e tests/e2e/case-communication.spec.ts

- **GIVEN** a case without contact moments
- **WHEN** you open its page
- **THEN** the Communication tab SHALL be present
- **AND** SHALL read No contact logged on this case yet

### Requirement: You log a contact from the case (REQ-KWZ-13)

You log a phone call or a visit without leaving the case. `CaseDetail` SHALL
offer a header action Log contact that opens a form over `contactmoment`
asking for the channel, the direction, the start time, the summary and who
called, with the open case passed as the value of `case`. After saving, the
new contact SHALL appear in the Communication tab.

**Feature tier**: MVP

#### Scenario: A logged call shows up in the tab
@e2e tests/e2e/case-communication.spec.ts

- **GIVEN** a case with no contact moments
- **WHEN** you choose Log contact, pick phone and inbound, write the summary Asked about the hearing date and save
- **THEN** the Communication tab SHALL show one row with channel phone, direction inbound and that summary

#### Scenario: The form does not ask for the KCC fields
@e2e tests/e2e/case-communication.spec.ts

- **GIVEN** the Log contact form is open
- **WHEN** you read its fields
- **THEN** it SHALL NOT ask for the identification method, the nature or the KCC employee
- **AND** the saved contact SHALL still carry the signed-in user as `kccEmployeeId`

#### Scenario: The case is prefilled once nextcloud-vue passes initial data
@e2e exclude The prefill waits on nextcloud-vue create-with-initial-data (placement.md, triage #6); until then the saved object is asserted, not the field.

- **GIVEN** the Log contact form opened from a case
- **WHEN** the form renders
- **THEN** the `case` field SHALL already hold the open case
