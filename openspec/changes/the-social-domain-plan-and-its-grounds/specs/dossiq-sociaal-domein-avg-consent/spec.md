## ADDED Requirements

### Requirement: Another domain answers only that a case exists (REQ-XDV-01)

A cross-domain lookup SHALL return, for a person, whether an open case
exists in another domain, which domain it is and the contact for it. It
SHALL return nothing else: no content, no status, no dates and no case
number. The content of the other domain's case SHALL stay unreadable.

#### Scenario: A consulent learns that a household is known elsewhere
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a person with an open Jeugdwet case and a Wmo consulent looking them up
- **WHEN** the lookup runs
- **THEN** it SHALL answer that a case exists, that it is Jeugdwet, and who to contact
- **AND** it SHALL return no content of that case

#### Scenario: Nothing leaks through the projection
@e2e exclude unit over the projection; CrossDomainExistenceTest

- **GIVEN** the same lookup
- **WHEN** the returned fields are enumerated
- **THEN** they SHALL be exactly the existence, the domain and the contact

### Requirement: The lookup requires a ground, chosen first and logged (REQ-XDV-02)

A cross-domain lookup SHALL require an authorisation ground to be chosen
before it runs, and SHALL be refused without one. The ground, the person
looked up, the requester, the moment and what was returned SHALL be written
to `sociaalDomeinAuditLog` in the same act as the answer. The log SHALL
answer what was looked up about a person.

#### Scenario: No ground, no answer
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a consulent who has chosen no ground
- **WHEN** they try to look a person up across domains
- **THEN** the lookup SHALL be refused
- **AND** the refusal SHALL say that a ground is required

#### Scenario: The ground is written with the answer
@e2e exclude unit; SociaalDomeinAuditLogTest

- **GIVEN** a lookup performed on a chosen ground
- **WHEN** the log is read
- **THEN** it SHALL hold the ground, the person, the requester, the moment and what was returned
- **AND** the field `authorisationGround` SHALL be populated rather than declared and empty

#### Scenario: A person can be told what was looked up about them
@e2e tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts

- **GIVEN** a person looked up twice in a year
- **WHEN** the log is read for that person
- **THEN** both lookups SHALL be returned with their grounds and their dates
