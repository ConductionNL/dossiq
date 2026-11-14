## ADDED Requirements

### Requirement: A contact moment names its contact and you log one from the contact page (REQ-KCC-011)

You see every call and visit with a person in one place, and you log the
next one from there. Schema `contactmoment` SHALL carry an optional property
`contact`: a string of format `uuid` with `referenceSemanticType`
`https://openregister.app/ns#Requester`, title Contact, `facetable: true`,
and the schema version SHALL move to 1.2.0. `ContactDetail` and
`OrganisationDetail` SHALL carry a header action `log-contact` of type
`open-form` over `contactmoment` with `props: {"contact": "@objectId"}` and
`includeFields` `notificationChannel`, `direction`, `startTime`, `nature`,
`summary` and `relatedCases`. The `contact-moments` list on both pages SHALL
show the moments where `contact = @objectId`, newest first, with the columns
start time, channel, direction and summary. `geidentificeerdeBurgerId` SHALL
keep its current meaning for the KCC bridge.

#### Scenario: Log a call from the contact page
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded person's contact page
- **WHEN** you press Log contact, pick channel phone and direction inbound, write a summary, and save
- **THEN** the saved `contactmoment` SHALL carry `contact` equal to the person's id
- **AND** the contact moments list SHALL show the new row first

#### Scenario: A person without contact moments says so
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded person with no contact moment naming them
- **WHEN** you open their page
- **THEN** the contact moments list SHALL show the empty state

#### Scenario: The schema carries the property in both register files
@e2e exclude a unit test over lib/Settings/register.d/40-kcc-werkplek.json and lib/Settings/dossiq_mock_register.json asserts the property, its semantic type and the version bump; no browser is needed

- **GIVEN** the two register files
- **WHEN** the unit test reads `contactmoment.properties.contact`
- **THEN** both SHALL carry the uuid string with the requester semantic type and version 1.2.0

#### Scenario: The KCC panel shows the caller's contact page
@e2e exclude Tier B (B20): the KCC panel with caller context stays outside this change; when it lands it links to ContactDetail and this scenario moves into its spec

- **GIVEN** an inbound call identified to a seeded person
- **WHEN** the KCC panel opens
- **THEN** it SHALL link to that person's contact page
