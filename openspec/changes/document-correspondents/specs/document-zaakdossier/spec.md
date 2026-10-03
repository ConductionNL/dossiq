## ADDED Requirements

### Requirement: REQ-ZAK-011 A document names its sender and its recipients, and both are parties

The `informatieobject` schema SHALL carry `sender` and `recipients`. Each
value SHALL be a party of the case the document is on, identified by the
party uuid or by the contact uid of a link written before the party model.
A value that no party of the case answers to SHALL be dropped rather than
stored, and a free-text name SHALL never be stored.

#### Scenario: A submitted name that matches no party is dropped
@e2e exclude a rule over the parties listing; covered by DocumentCorrespondentsTest, which passes a typed name and a matching uuid through the same call

- **GIVEN** a case whose parties are Jan Jansen and Gemeente Utrecht
- **WHEN** a document is saved with `sender` set to the words `Jan Jansen`
- **THEN** `sender` SHALL be empty
- **AND** saving the same document with Jan Jansen's party uuid SHALL store that uuid

#### Scenario: A correspondent is resolved by e-mail address
@e2e exclude the resolver runs server side with no surface of its own; covered by DocumentCorrespondentsTest over a parties listing carrying an e-mail address

- **GIVEN** a party of the case holding the address `jan@example.org`
- **WHEN** a document is saved with `sender` set to `JAN@EXAMPLE.ORG`
- **THEN** `sender` SHALL hold that party's uuid

### Requirement: REQ-ZAK-012 Direction decides which correspondent a document may carry

An outgoing document SHALL carry recipients and no sender. An incoming
document SHALL carry a sender and no recipients. An internal document MAY
carry either. A correspondent that contradicts the direction SHALL be
dropped, and the document SHALL still be saved.

#### Scenario: A sender on an outgoing document is dropped
@e2e exclude a rule with no surface; covered by DocumentCorrespondentsTest

- **GIVEN** a document with `direction` outgoing
- **WHEN** it is saved with both a sender and a recipient, each a party of the case
- **THEN** the stored `sender` SHALL be empty
- **AND** the stored `recipients` SHALL hold the recipient

### Requirement: REQ-ZAK-013 The writers set the correspondent, not the person

A write that files an outgoing document on a case SHALL set `recipients`
from the parties addressed by that send and SHALL set `direction` to
outgoing. The mail intake SHALL set `sender` from the message's From
address, matched against the parties of the case, and SHALL set
`direction` to incoming. An address no party holds SHALL leave `sender`
empty rather than create a party.

#### Scenario: A generated letter names its addressee
@e2e tests/e2e/document-correspondents.spec.ts

- **GIVEN** a case whose requester is a party
- **WHEN** a letter is generated on the case from a library template
- **AND** you open the letter's properties on the Files tab
- **THEN** Recipients SHALL name the requester
- **AND** Direction SHALL read outgoing

#### Scenario: An intake attachment names its sender
@e2e exclude the inbound writer runs in the mail intake listener, which has no page to drive; covered by InboundMailIntake's unit test over a fixture message with a From header

- **GIVEN** a mail from `jan@example.org` filed on a case whose party holds that address
- **WHEN** the document the intake filed is read
- **THEN** `sender` SHALL hold that party's uuid
- **AND** `direction` SHALL be incoming

### Requirement: REQ-ZAK-014 A dispatch record is written per correspondent

Every correspondent set on a document SHALL be recorded as a `dispatch`
row carrying the document, the case, the party and the role `afzender` or
`geadresseerde`, with `sendDate` on an outgoing document and
`receiveDate` on an incoming one. `contactPersonName` SHALL NOT be
written.

#### Scenario: Two recipients give two dispatch rows
@e2e exclude a write with no surface of its own; covered by CorrespondentWriterTest over a doubled record store

- **GIVEN** an outgoing document on a case with two parties addressed
- **WHEN** the correspondents are written
- **THEN** two dispatch rows SHALL exist, each naming one party in the role `geadresseerde`
- **AND** each SHALL carry the document, the case and a `sendDate`

### Requirement: REQ-ZAK-015 The correspondents are on screen and can be filtered

The document properties dialog SHALL edit `sender` and `recipients` with a
picker offering the parties of the case. The dossier listing SHALL carry
both resolved to names and SHALL accept a `correspondent` filter naming
one party. The People tab SHALL list, per party, the documents that party
sent or received.

#### Scenario: The properties dialog offers the parties of the case
@e2e tests/e2e/document-correspondents.spec.ts

- **GIVEN** a case with two parties and a document on it
- **WHEN** you open the document's properties
- **THEN** the Sender picker SHALL offer exactly those two parties
- **AND** saving a sender SHALL show that party's name on the People tab under the documents they sent
