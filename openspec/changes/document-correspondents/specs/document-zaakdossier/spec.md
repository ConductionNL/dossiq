## ADDED Requirements

### Requirement: REQ-ZAK-011 A document records its sender and recipient

The document projection on a case SHALL carry `sender`, `recipient` and
`direction`. The beschikking delivery SHALL write `recipient` and
`direction: outbound` when it files the letter. The mail intake SHALL write
`sender` and `direction: inbound` from the message's From header. The Files
tab SHALL declare Sender and Recipient as columns and the properties dialog
SHALL show both fields.

#### Scenario: A delivered beschikking names its recipient
@e2e tests/e2e/document-correspondents.spec.ts

- **GIVEN** a beschikking delivered to the requester of a case
- **WHEN** you open the letter's properties on the Files tab
- **THEN** Recipient SHALL name the requester
- **AND** Direction SHALL read outbound

#### Scenario: An intake attachment names its sender
@e2e exclude the inbound writer runs in the mail intake listener; covered by its unit test over a fixture message with a From header

- **GIVEN** a mail from `jan@example.org` filed on a case by the intake
- **WHEN** the attachment's projection is read
- **THEN** `sender.name` SHALL be `jan@example.org`
- **AND** `direction` SHALL be `inbound`

### Requirement: REQ-ZAK-012 The dispatch schema is retired

The `dispatch` schema and its seed rows SHALL be removed once the two
fields exist, and no code SHALL reference the slug.

#### Scenario: Nothing references dispatch
@e2e exclude structural; covered by a unit test that greps `lib/` and `src/` for the slug

- **GIVEN** the change is applied
- **WHEN** `lib/` and `src/` are searched for `dispatch`
- **THEN** no schema reference SHALL remain
