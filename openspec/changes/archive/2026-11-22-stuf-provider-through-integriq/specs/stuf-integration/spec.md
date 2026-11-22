# stuf-integration (delta)

@e2e exclude Pure SOAP and event integration with no browser surface; covered by PHPUnit
(StufProviderQueryListenerTest, StufControllerCoverageTest, StufRegisterContractTest).

## ADDED Requirements

### Requirement: Dossiq answers StUF provider questions through integriq

Dossiq SHALL answer integriq's `StufCaseQueryEvent` and `StufPersonQueryEvent` only for an integriq
source a `stufEndpoint` names in `integriqSource`. A case query SHALL be answered by identificatie,
each case carrying the zaaktype the endpoint's `zaaktypeMappings` gives its case type; a query
without an identificatie SHALL be refused. A person query SHALL be answered from the case that names
the BSN as its initiator. `StufCaseMutationEvent` and `StufDocumentMutationEvent` SHALL be refused.
When the case store cannot be read the event SHALL stay unanswered, never answered empty.

#### Scenario: A case is found by its identificatie

- GIVEN a case 2026-0042 whose case type maps to `Omgevingsvergunning kap`
- WHEN integriq asks for identificatie 2026-0042 on the endpoint's source
- THEN dossiq answers one case with that zaaktype and startdatum in StUF date form

#### Scenario: Another app's source

- GIVEN no stufEndpoint names the source
- WHEN the event arrives
- THEN dossiq leaves it unanswered

### Requirement: Provider requests reach integriq on its own URLs

A zaaksysteem SHALL post its StUF provider requests to integriq's
`/api/stuf-zkn/provider/{sourceId}/cases` and `/persons`. Dossiq SHALL NOT serve `/api/stuf/cases`,
`/api/stuf/persons`, `/api/stuf/zaken` or `/api/stuf/personen`: they forwarded to integriq for one
release and are removed.

#### Scenario: The Dutch cases URL is gone

- GIVEN a dossiq release after the forwarding release
- WHEN a zaaksysteem posts a zakLv01 to `/api/stuf/zaken`
- THEN no dossiq route answers it

## REMOVED Requirements

### REQ-001: Inbound StUF SOAP routing + message-type dispatch

**Reason:** integriq parses the envelope and dispatches by message type (decision 199). Dossiq has
no StUF route of its own.

### REQ-002: StUF-ZKN case handlers (zakLk01, zakLv01, edcLk01)

**Reason:** replaced by "Dossiq answers StUF provider questions through integriq".

### REQ-003: StUF-BG person query handler (npsLv01)

**Reason:** replaced by the same requirement.

### REQ-004: Bidirectional StUF↔OpenRegister field mapping with date and enum transforms

**Reason:** dossiq no longer maps StUF fields; the listener names the few fields a case answer carries.

### REQ-005: StUF SOAP envelope construction with namespaces, stuurgegevens, noValue

**Reason:** integriq builds every StUF answer.
