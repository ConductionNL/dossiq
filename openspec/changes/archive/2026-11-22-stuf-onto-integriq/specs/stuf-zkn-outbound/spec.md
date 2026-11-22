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

### Requirement: Confirmations reach integriq on its own URL

A zaaksysteem SHALL post its asynchronous confirmations to integriq's `/api/stuf-zkn/confirmations`;
integriq authenticates and records them. Dossiq SHALL NOT serve `/api/stuf/inbound` or
`/api/stuf/inkomend`: they forwarded to integriq for one release and are removed.

#### Scenario: The old confirmation URL is gone

- GIVEN a dossiq release after the forwarding release
- WHEN a confirmation is posted to /api/stuf/inbound or /api/stuf/inkomend
- THEN no dossiq route answers it

### Requirement: A failed message still reaches the administrators

When integriq concludes a message dossiq asked for as failed, dossiq SHALL notify its
administrators with the Notifier subject it used before: CIRCUIT_OPEN as stuf_circuit_open,
TIMEOUT as stuf_timeout, anything else as stuf_permanent_error.

#### Scenario: A permanent failure

- GIVEN integriq concludes a dossiq vrijBericht with fout StUF064
- WHEN the conclusion is dispatched
- THEN the administrators get a stuf_permanent_error notification naming the endpoint

## MODIFIED Requirements

### Requirement: Outbound REST surface

The system SHALL expose admin-gated REST endpoints to list configured endpoints
(with their integriq link as health), query the audit log, and send a vrijBericht.
It SHALL expose no inbound SOAP reception endpoint.

#### Scenario: Endpoint listing is admin-gated

- **WHEN** an unauthenticated client calls `GET /api/stuf/endpoints`
- **THEN** the request MUST be rejected (not 404), and an authenticated admin MUST receive the endpoint list with a `health` block per endpoint

## REMOVED Requirements

### Requirement: Async confirmation

**Reason:** integriq receives, authenticates and records the confirmation (decision 193); see
"Confirmations reach integriq on its own URL".
