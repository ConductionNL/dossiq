# stuf-zkn-outbound (delta)

## ADDED Requirements

### Requirement: Outbound StUF goes through integriq

Dossiq SHALL NOT send StUF itself. An outbound message SHALL be asked of integriq with
`StufZdsCaseRequestedEvent` on the integriq source the endpoint names in `integriqSource`. An
endpoint without one SHALL be refused (400) with nothing sent; integriq not answering SHALL be
reported as unavailable (503), never as sent.

#### Scenario: A vrij bericht is sent by integriq

- GIVEN an endpoint with `integriqSource` src-9
- WHEN an administrator sends vrijBericht meldBesluit
- THEN integriq receives a vrijBericht request for src-9 and its result is the answer

#### Scenario: Integriq disabled

- GIVEN integriq is not installed or does not answer
- WHEN an administrator sends a vrij bericht
- THEN the answer is 503 INTEGRIQ_UNAVAILABLE

### Requirement: Confirmations go to integriq only

Integriq SHALL receive the zaaksysteem's asynchronous confirmations on its own
`/api/stuf-zkn/confirmations`. Dossiq kept `/api/stuf/inbound` and `/api/stuf/inkomend` as
forwarders for one release; from the release after that one dossiq SHALL serve neither URL and
SHALL hold no confirmation forwarder.

#### Scenario: The old confirmation URL is not dossiq's

- GIVEN the release after the forwarding release is installed
- WHEN the route table is read
- THEN neither /api/stuf/inbound nor /api/stuf/inkomend is routed, and the admin REST routes
  /api/stuf/endpoints, /api/stuf/messages and /api/stuf/outbound remain

### Requirement: A failed message still reaches the administrators

When integriq concludes a message dossiq asked for as failed, dossiq SHALL notify its
administrators with the Notifier subject it used before: CIRCUIT_OPEN as stuf_circuit_open,
TIMEOUT as stuf_timeout, anything else as stuf_permanent_error.

#### Scenario: A permanent failure

- GIVEN integriq concludes a dossiq vrijBericht with fout StUF064
- WHEN the conclusion is dispatched
- THEN the administrators get a stuf_permanent_error notification naming the endpoint
