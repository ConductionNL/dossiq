## ADDED Requirements

### Requirement: A case type declares what must be answered before a case exists (REQ-TRIAGE-01)

A case type SHALL declare which fields must be answered before a case can
be created, separately from the fields required before a case is
complete. The communication channel and the confidentiality SHALL be on
the first list by default for a new case type. Creating a case without a
field on that list SHALL be refused with a 4xx carrying `{message, error}`
naming the field.

#### Scenario: a case cannot be created without its channel
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a case type with the default declaration
- **WHEN** a handler creates a case with no communication channel
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the channel

#### Scenario: the confidentiality is asked for at creation
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** the create-case form for that case type
- **WHEN** a handler opens it
- **THEN** the confidentiality SHALL be asked for

#### Scenario: an internal case type may ask for neither

- **GIVEN** a case type declaring neither field required before creation
- **WHEN** a handler creates a case without them
- **THEN** it SHALL be created

#### Scenario: required before complete is not required before creation

- **GIVEN** a case type requiring the applicant's address before completion
- **WHEN** a handler creates a case without it
- **THEN** the case SHALL be created and read incomplete

### Requirement: A classification that is an access rule is required before the case exists (REQ-TRIAGE-02)

A case type SHALL be able to declare a classification, a sensitivity, an
action facet and an insight level, and SHALL be able to mark the
classification as an access rule. Where it is an access rule, a case SHALL
NOT be created without it. Where the classification scheme cannot be
resolved, creation SHALL be refused rather than an unreachable case being
created.

#### Scenario: an unclassified case is not created
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a case type whose classification is an access rule
- **WHEN** a handler creates a case without choosing one
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the classification

#### Scenario: the classification decides who can reach the case
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a case created with a restricted classification
- **WHEN** a handler outside that classification searches for it
- **THEN** it SHALL NOT be found

#### Scenario: an unresolvable scheme fails closed

- **GIVEN** a case type naming a classification scheme that does not resolve
- **WHEN** a case is created
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the scheme

#### Scenario: the four facets are recorded when declared
@e2e tests/e2e/intake-triage-and-refusal.spec.ts

- **GIVEN** a case type declaring all four facets
- **WHEN** a case is created with them
- **THEN** the case SHALL carry the classification, the sensitivity, the action facet and the insight level
