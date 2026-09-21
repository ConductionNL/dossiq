## ADDED Requirements

### Requirement: The Berichtenbox adapter reaches integriq (REQ-BB-20)

A letter dossiq sends leaves the building. `lib/Service/BerichtenboxAdapter/`
SHALL carry an adapter that dispatches integriq's digital post send event and
returns the reference integriq answers with, selectable through the existing
`berichtenbox_adapter` app config key. dossiq SHALL carry no Berichtenbox
protocol, no credentials and no provider choice.

#### Scenario: A sent message carries integriq's reference
@e2e exclude Backend adapter, covered by PHPUnit.

- **GIVEN** integriq is installed with its digital post seam available
- **AND** `berichtenbox_adapter` selects the integriq adapter
- **WHEN** a handler sends a message on a case
- **THEN** the send event SHALL be dispatched with the case, the recipient and
  the message
- **AND** the reference integriq answers with SHALL be stored on the message

### Requirement: The adapter refuses rather than simulating (REQ-BB-21)

When integriq is absent, or reports its digital post seam unavailable, the
adapter SHALL refuse with a named error. It SHALL NOT fall back to the mock
adapter. A simulated delivery that reads as a real one is how a citizen stops
being notified without anyone noticing.

#### Scenario: No integriq, no send
@e2e exclude Backend adapter, covered by PHPUnit.

- **GIVEN** `berichtenbox_adapter` selects the integriq adapter
- **AND** integriq is not installed
- **WHEN** a handler sends a message
- **THEN** the send SHALL be refused with an error naming integriq
- **AND** no message SHALL be recorded as sent

#### Scenario: An unavailable seam is refused, not mocked
@e2e exclude Backend adapter, covered by PHPUnit.

- **GIVEN** integriq is installed and reports its digital post seam unavailable
- **WHEN** a handler sends a message
- **THEN** the send SHALL be refused naming the unavailable seam
- **AND** the mock adapter SHALL NOT be used

### Requirement: integriq is resolved by fleet id, never by a literal (REQ-BB-22)

The adapter SHALL resolve integriq through `FleetAppId`, which answers to
both the current and the previous app id. It SHALL NOT contain a literal app
id or a literal integriq class name, because an id that nothing answers to
makes the integration a silent no-op rather than an error.

#### Scenario: A renamed integriq still resolves
@e2e exclude Backend resolution, covered by PHPUnit.

- **GIVEN** integriq installed under either of its two ids
- **WHEN** the adapter resolves it
- **THEN** it SHALL find the app under either id

### Requirement: Delivery arrives as an event (REQ-BB-23)

dossiq SHALL listen for integriq's delivery event and SHALL update the stored
message on the case, writing a timeline entry. dossiq SHALL NOT poll the
provider itself.

#### Scenario: A delivered letter updates the case
@e2e exclude Backend listener, covered by PHPUnit.

- **GIVEN** a message sent through the integriq adapter
- **WHEN** integriq dispatches its delivery event for that reference
- **THEN** the stored message's status SHALL read delivered
- **AND** the case timeline SHALL carry an entry naming the delivery
