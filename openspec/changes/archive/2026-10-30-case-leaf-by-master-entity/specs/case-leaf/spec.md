# case-leaf

## ADDED Requirements

### Requirement: A host app reads a party's cases by master entity

Dossiq SHALL answer the cases whose requester is a brpPerson or kvkCompany carrying a given master entity, read with the calling user's rights, with the status filter open, closed or all. Open cases SHALL come first by deadline, closed cases after them newest first. Each row SHALL carry the identifier, title, case type title, status name and colour, whether the status is final, the deadline, the end date, the handler's uid and name, and the link to the case page.

#### Scenario: Buurtvereniging Oost's cases in pipelinq

- **GIVEN** the kvkCompany Buurtvereniging Oost carries master entity M and requested one open and two closed cases
- **WHEN** a host app reads master entity M
- **THEN** it gets the open case first, then the closed cases newest first
- **AND** a case the user may not read is not among them

#### Scenario: An unknown master entity

- **WHEN** a host app reads an empty or unknown master entity
- **THEN** it gets no cases and no case is read
