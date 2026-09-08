## ADDED Requirements

### Requirement: New case from a contact opens the case form with the requester filled in (REQ-IS-5)

You file a case for the person in front of you without picking them again.
`ContactDetail` and `OrganisationDetail` SHALL carry a header action
`new-case-for-contact` of type `open-form` over schema `case` in register
`dossiq` with `props: {"requester": "@objectId"}`. The form SHALL be the
same case form `Dashboard` opens: `requester` renders through the initiator
picker with the contact already chosen, and on save the projection fields
`initiatorType`, `initiatorSourceId` and `initiatorDisplayName` SHALL be
written from that choice as REQ-IS-4 requires. On success the cases list on
the contact page SHALL show the new case.

#### Scenario: File a case for a person
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded person's contact page
- **WHEN** you press New case, enter a title and a case type, and save
- **THEN** the saved case SHALL carry `requester` equal to the person's id
- **AND** `initiatorDisplayName` SHALL equal the person's display name
- **AND** the cases list on the contact page SHALL show the new case

#### Scenario: The requester is prefilled, not retyped
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** the New case form opened from a contact page
- **WHEN** you read the requester field
- **THEN** it SHALL show the contact's name before you type anything
