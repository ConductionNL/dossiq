# stuf-integration (delta)

@e2e exclude Pure SOAP and event integration with no browser surface; covered by PHPUnit
(StufProviderQueryListenerTest, StufControllerProviderForwardTest, StufIntegriqBridgeTest).

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

### Requirement: Provider requests on the old URLs reach integriq

For one release `/api/stuf/cases`, `/api/stuf/persons`, `/api/stuf/zaken` and `/api/stuf/personen`
SHALL forward the raw envelope to integriq with `StufProviderRequestReceivedEvent`, naming the
integriq source of the endpoint whose `recipientApplication` is the envelope's zender applicatie,
and return integriq's status and body. Integriq not answering SHALL be a 503.

#### Scenario: The Dutch cases URL

- GIVEN a zaaksysteem posts a zakLv01 to `/api/stuf/zaken`
- WHEN the controller handles it
- THEN integriq receives the unchanged envelope for service `cases`

## REMOVED Requirements

### REQ-001: Inbound StUF SOAP routing + message-type dispatch

**Reason:** integriq parses the envelope and dispatches by message type (decision 199). Dossiq's
routes only forward, for one release.

### REQ-002: StUF-ZKN case handlers (zakLk01, zakLv01, edcLk01)

**Reason:** replaced by "Dossiq answers StUF provider questions through integriq".

### REQ-003: StUF-BG person query handler (npsLv01)

**Reason:** replaced by the same requirement.

### REQ-004: Bidirectional StUF↔OpenRegister field mapping with date and enum transforms

**Reason:** dossiq no longer maps StUF fields; the listener names the few fields a case answer carries.

### REQ-005: StUF SOAP envelope construction with namespaces, stuurgegevens, noValue

**Reason:** integriq builds every StUF answer.
