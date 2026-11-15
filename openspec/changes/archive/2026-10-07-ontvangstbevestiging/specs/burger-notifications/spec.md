## ADDED Requirements

### Requirement: The acknowledgement of receipt has a trigger (REQ-TERM-020)

A case created from an electronic submission SHALL trigger an
ontvangstbevestiging without a person acting, per Awb 4:3a. The trigger
SHALL be a declaration on the case type and SHALL NOT be a step in a
workflow definition. A case type SHALL declare which intake channels owe
an acknowledgement, defaulting to every electronic channel. A case created
by hand SHALL NOT owe one unless the case type says so.

#### Scenario: an aanvraag arrives through the portal
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type with the default declaration
- **WHEN** a citizen submits an aanvraag through the portal
- **THEN** an ontvangstbevestiging SHALL be sent to them
- **AND** no handler action SHALL be needed

#### Scenario: an aanvraag arrives by mail
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case created from an accepted inbound message
- **WHEN** the case is created
- **THEN** an ontvangstbevestiging SHALL be sent to the sender

#### Scenario: a case typed at the balie owes nothing
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type whose declaration names electronic channels only
- **WHEN** a handler creates a case by hand
- **THEN** no ontvangstbevestiging SHALL be sent

#### Scenario: a case type cannot silently lose the duty

- **GIVEN** a case type whose acknowledgement declaration is removed
- **WHEN** it is published
- **THEN** publication SHALL warn that the statutory acknowledgement is off
- **AND** the warning SHALL name Awb 4:3a

### Requirement: The acknowledgement says what was received and by when it is decided (REQ-TERM-021)

The message SHALL name the case kenmerk, what was received, the statutory
term and its end date, how to follow the case, and who to contact. It
SHALL be sent in Dutch, and in the language the case type declares where
it declares one. It SHALL quote back only what the case type allows the
citizen to see.

#### Scenario: the citizen learns their kenmerk and their deadline
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case with a bound statutory term
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL carry the kenmerk and the end date of the term

#### Scenario: a field the citizen may not see is not quoted back

- **GIVEN** a case carrying a field marked not visible to the citizen
- **WHEN** the ontvangstbevestiging is rendered
- **THEN** that field SHALL NOT appear in it

#### Scenario: a case with no statutory term still confirms receipt
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type with no beslistermijn
- **WHEN** a case is created from an electronic submission
- **THEN** an ontvangstbevestiging SHALL still be sent
- **AND** it SHALL say that no statutory term applies

### Requirement: The channel is the citizen's where they chose one (REQ-TERM-022)

The acknowledgement SHALL be sent through the channel the citizen chose,
where a choice is recorded, and otherwise through the case type's default.
Where the case type declares that content stays on the platform, the
message SHALL say a message is waiting and SHALL carry no case content.

#### Scenario: a citizen who chose the portal is not mailed the content
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a citizen whose recorded choice is the portal
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it SHALL be delivered in the portal

#### Scenario: content stays on the platform
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type declaring that content stays on the platform
- **WHEN** the acknowledgement is sent by e-mail
- **THEN** the e-mail SHALL say a message is waiting
- **AND** it SHALL carry no case content

#### Scenario: no recorded choice falls back to the case type's default

- **GIVEN** a citizen with no recorded channel choice
- **WHEN** the acknowledgement is sent
- **THEN** the case type's default channel SHALL be used

### Requirement: The acknowledgement is recorded on the case (REQ-TERM-023)

Sending an acknowledgement SHALL write an outbound communication record on
the case carrying the moment, the channel, the recipient and the template
version. Whether receipt was confirmed SHALL be answerable from the case
without reading a server log.

#### Scenario: a handler can see that receipt was confirmed
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case whose acknowledgement was sent
- **WHEN** a handler opens the case
- **THEN** the record SHALL show the moment, the channel and the recipient

### Requirement: A failed acknowledgement is visible and retried (REQ-TERM-024)

A failure to send SHALL NOT block the creation of the case, SHALL be
retried, and SHALL show on the case as an unmet statutory duty until it is
sent or a person records that it was met another way. It SHALL NOT be
recorded only in the application log.

#### Scenario: a mail server outage does not stop a case being created
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** an unreachable mail transport
- **WHEN** a citizen submits an aanvraag
- **THEN** the case SHALL be created
- **AND** the acknowledgement SHALL be queued

#### Scenario: an acknowledgement that never went out is findable
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case whose acknowledgement failed every retry
- **WHEN** a handler opens the case
- **THEN** the case SHALL show the duty as unmet

#### Scenario: a handler records that it was met another way
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case showing an unmet acknowledgement
- **WHEN** a handler records that it was confirmed by post
- **THEN** the duty SHALL read met
- **AND** the record SHALL name who said so and when

### Requirement: Other automatic moments ride the same declaration (REQ-TERM-025)

A case type SHALL declare a list of moments at which the applicant is told
something automatically. The acknowledgement SHALL be the entry carrying
the statutory flag. A request for something from the applicant SHALL be a
distinct moment from a status change.

#### Scenario: asking for something is not the same message as moving on
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type declaring a moment for an incomplete aanvraag and a moment for a status change
- **WHEN** the case becomes incomplete
- **THEN** the applicant SHALL receive the request message
- **AND** it SHALL NOT be the status-change message

#### Scenario: the statutory moment cannot be deleted unnoticed

- **GIVEN** a case type's declared moments
- **WHEN** the statutory entry is removed and the type is published
- **THEN** publication SHALL warn, naming Awb 4:3a
