# stuf-integration (delta)

@e2e exclude Pure SOAP and event integration with no browser surface; covered by PHPUnit
(ConnectedSystemCaseWriterTest, StufProviderQueryListenerTest).

## ADDED Requirements

### Requirement: A write over StUF arrives through integriq only

Dossiq SHALL carry out a case or document write only when it arrives on integriq's
`StufCaseMutationEvent` or `StufDocumentMutationEvent` with a generic operation, for an integriq
source a `stufEndpoint` names in `integriqSource`. Dossiq SHALL expose no StUF write route of its
own. An event without an operation SHALL be refused. A write on a source no endpoint names SHALL stay
unanswered.

#### Scenario: A mapped create is carried out

- GIVEN an endpoint that names the source and accepts writes
- WHEN integriq's event carries a `create` operation
- THEN the writer gets the operation with the endpoint's mappings and the event is accepted

#### Scenario: An event without an operation

- GIVEN an integriq that sends StUF fields and no operation
- WHEN a mutation event arrives
- THEN dossiq refuses it and writes nothing

### Requirement: A connected system writes as the service account

A write for a connected system SHALL run as the background service account with OpenRegister's
access rules on. It SHALL be refused as forbidden, before anything is read, when the connection does
not set `acceptsWrites` or the service account cannot be used. A store that fails SHALL throw, so the
adapter answers a fault and never a confirmation.

#### Scenario: The endpoint did not opt in

- GIVEN an endpoint without `acceptsWrites`
- WHEN a create arrives
- THEN the outcome is a forbidden refusal and the store is not called

### Requirement: A case opened by a connected system keeps its origin

A create SHALL need an identifier, a title and a zaaktype the connection maps to a dossiq case type.
The case SHALL be stored with that case type, its initial status, `originSystem` and
`originIdentifier`. A second create with the same identifier on the same connection SHALL be refused
as a conflict.

#### Scenario: The stored case passes the shipped schema

- GIVEN a create with a mapped zaaktype and an initiator
- WHEN the case is written
- THEN the payload validates against dossiq's case schema and carries the origin fields

### Requirement: A connected system changes descriptive fields only

An update SHALL find the case by the system's own identifier, or by dossiq's case number, and SHALL
change only the title, the description and the planned end date. An unknown case SHALL be refused as
not found, and a message that changes none of the three as refused.

#### Scenario: A start date in an update is ignored

- GIVEN an update that carries a new title and a different start date
- WHEN it is carried out
- THEN the title changes and the start date does not

### Requirement: A document from a connected system takes the upload path

An add-document SHALL be filed through the screened upload path a handler's upload takes, on a case
that exists, with the document type the connection maps the system's type name to. It SHALL be
refused without content, without a file name, for an unmapped type, and when the upload path refuses
it. The reason returned SHALL not quote an internal error.

#### Scenario: An unmapped document type

- GIVEN a connection that maps only `Besluit`
- WHEN a document of type `Aanvraag` arrives
- THEN it is refused and nothing is uploaded

## MODIFIED Requirements

### Requirement: Dossiq answers StUF provider questions through integriq

Dossiq SHALL answer integriq's `StufCaseQueryEvent` and `StufPersonQueryEvent` only for an integriq
source a `stufEndpoint` names in `integriqSource`. A case query SHALL be answered by identificatie,
each case carrying the zaaktype the endpoint's `zaaktypeMappings` gives its case type; a query
without an identificatie SHALL be refused. A person query SHALL be answered from the case that names
the BSN as its initiator. `StufCaseMutationEvent` and `StufDocumentMutationEvent` SHALL be handed to
the connected system writer. When the case store cannot be read the event SHALL stay unanswered,
never answered empty.

#### Scenario: A case is found by its identificatie

- GIVEN a case 2026-0042 whose case type maps to `Omgevingsvergunning kap`
- WHEN integriq asks for identificatie 2026-0042 on the endpoint's source
- THEN dossiq answers one case with that zaaktype and startdatum in StUF date form
