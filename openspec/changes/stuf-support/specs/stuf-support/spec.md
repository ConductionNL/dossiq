---
status: implemented
---
# StUF Protocol Support Specification

## Purpose

Procest currently implements ZGW APIs (Zaken, Catalogi, Documenten, Besluiten) for case management via REST controllers (`ZrcController`, `ZtcController`, `DrcController`, `BrcController`). However, many Dutch municipalities still rely on StUF (Standaard Uitwisseling Formaat) -- especially StUF-ZKN (Zaak-Kennis) for case management and StUF-BG (Basis Gemeentelijke) for person/address lookups. This spec defines how Procest supports StUF alongside ZGW, providing a dual API surface over the same OpenRegister case data.

StUF support enables Procest to integrate with legacy form systems (e.g., formulierenmotoren that submit cases via StUF-ZKN), legacy case systems during migration periods, and BRP person lookups via StUF-BG. The approach leverages OpenConnector's existing SOAP infrastructure (SOAPService with StUF-ZKN `edcLk01` awareness) for outbound StUF calls, while adding inbound StUF endpoints to Procest for receiving SOAP messages from legacy consumers.

**Standards**: StUF 3.01, StUF-ZKN 3.10, StUF-BG 3.10, ZGW APIs (VNG), RGBZ, GEMMA
**Feature tier**: V1 (outbound StUF via OpenConnector), V2 (inbound StUF endpoints in Procest)

---

## Architecture Overview

```
                          Inbound StUF (V2)
                          Legacy systems send SOAP messages to Procest
                          ┌──────────────────────────────────┐
                          │  Legacy Formulierensysteem       │
                          │  Legacy Zaaksysteem              │
                          └────────────┬─────────────────────┘
                                       │ SOAP (StUF-ZKN/BG)
                          ┌────────────▼─────────────────────┐
                          │  Procest StUF Controller          │
                          │  - Raw XML POST handler           │
                          │  - StUF message parser            │
                          │  - StUF → OpenRegister mapper     │
                          └────────────┬─────────────────────┘
                                       │ Internal API
┌──────────────────────────────────────▼──────────────────────┐
│  OpenRegister (procest register)                             │
│  - case, caseType, status, role, result, decision schemas   │
│  - Single source of truth for all case data                 │
└──────────────────────────────────────▲──────────────────────┘
                                       │ Internal API
                          ┌────────────┴─────────────────────┐
                          │  OpenConnector (SOAP outbound)    │
                          │  - SOAPService (existing)         │
                          │  - StUF-ZKN/BG message builder    │
                          │  - mTLS / WS-Security auth        │
                          └────────────┬─────────────────────┘
                                       │ SOAP (StUF-ZKN/BG)
                          ┌────────────▼─────────────────────┐
                          │  External Legacy Services         │
                          │  - BRP (StUF-BG personen)         │
                          │  - Legacy zaaksysteem              │
                          │  - Gemeentelijk DMS               │
                          └──────────────────────────────────┘
                          Outbound StUF (V1)
                          Procest/OpenConnector queries legacy systems
```

### Coexistence Principle

StUF and ZGW share the same underlying OpenRegister data. A case created via StUF-ZKN `zakLk01` is stored in the same `case` schema and is immediately visible via the ZGW Zaken API (`ZrcController`) and the Procest frontend. Conversely, a case created via the ZGW API or UI can be queried via StUF-ZKN `zakLv01`. The translation layer maps between the two representations without duplicating data.

---

## Data Model Mapping

### StUF-ZKN to Procest Case Mapping

| StUF-ZKN Field | XML Path | Procest Case Property | ZGW Mapping | Notes |
|----------------|----------|----------------------|-------------|-------|
| `zaakidentificatie` | `zakLk01/object/identificatie` | `identifier` | `identificatie` | Auto-generated if not provided |
| `omschrijving` | `zakLk01/object/omschrijving` | `title` | `omschrijving` | Required |
| `toelichting` | `zakLk01/object/toelichting` | `description` | `toelichting` | Optional |
| `startdatum` | `zakLk01/object/startdatum` | `startDate` | `startdatum` | Format: `YYYYMMDD` -> ISO 8601 |
| `einddatum` | `zakLk01/object/einddatum` | `endDate` | `einddatum` | Format: `YYYYMMDD` -> ISO 8601 |
| `einddatumGepland` | `zakLk01/object/einddatumGepland` | `plannedEndDate` | `einddatumGepland` | |
| `uiterlijkeEinddatumAfdoening` | `zakLk01/object/uiterlijkeEinddatumAfdoening` | `deadline` | `uiterlijkeEinddatumAfdoening` | |
| `isVan/gerelateerde/code` | Nested element | `caseType` (resolved by code) | `zaaktype` | Resolved via caseType lookup |
| `vertrouwelijkAanduiding` | `zakLk01/object/vertrouwelijkAanduiding` | `confidentiality` | `vertrouwelijkheidaanduiding` | Enum mapping required |
| `heeftAlsInitiator` | Nested BSN/vestigingsnummer | `role` (genericRole=initiator) | `rol` | Creates a Role object |
| `heeftAlsBehandelaar` | Nested medewerker | `assignee` + role | `rol` | Handler assignment |

### StUF-ZKN Status Mapping

| StUF-ZKN Field | XML Path | Procest Property | Notes |
|----------------|----------|-----------------|-------|
| `datumStatusGezet` | `heeft/datumStatusGezet` | StatusRecord `dateSet` | Format: `YYYYMMDDHHmmss` -> ISO 8601 |
| `gpiStatusType/code` | Nested element | StatusRecord `statusType` | Resolved via statusType lookup |
| `statustoelichting` | `heeft/statustoelichting` | StatusRecord `description` | |

### StUF-BG Person Mapping

| StUF-BG Field | XML Path | Description | OpenRegister Property |
|---------------|----------|-------------|----------------------|
| `inp.bsn` | `npsLv01/gelijk/inp.bsn` | Burgerservicenummer | `bsn` |
| `geslachtsnaam` | `npsLa01/antwoord/.../geslachtsnaam` | Family name | `lastName` |
| `voorvoegselGeslachtsnaam` | Nested element | Name prefix | `namePrefix` |
| `voornamen` | Nested element | Given names | `firstName` |
| `geboortedatum` | `inp.geboortedatum` | Date of birth | `dateOfBirth` |
| `verblijfsadres` | Nested AOA element | Residential address | `address` (composite) |
| `sub.verblijfBuitenland` | Nested element | Foreign address | `foreignAddress` |

### Confidentiality Enum Mapping

| StUF Value | Procest Value | ZGW Dutch |
|------------|--------------|-----------|
| `OPENBAAR` | `public` | openbaar |
| `BEPERKT OPENBAAR` | `restricted` | beperkt_openbaar |
| `INTERN` | `internal` | intern |
| `ZAAKVERTROUWELIJK` | `case_sensitive` | zaakvertrouwelijk |
| `VERTROUWELIJK` | `confidential` | vertrouwelijk |
| `CONFIDENTIEEL` | `highly_confidential` | confidentieel |
| `GEHEIM` | `secret` | geheim |
| `ZEER GEHEIM` | `top_secret` | zeer_geheim |

---

## Requirements

---

### REQ-STUF-001: Outbound StUF-BG Person Lookup via OpenConnector

The system MUST support querying external StUF-BG services for person data (BRP) via OpenConnector's SOAP infrastructure. This enables case handlers to look up citizen information by BSN when creating or processing cases.

**Feature tier**: V1


#### Scenario STUF-001a: Look up person by BSN

- GIVEN an OpenConnector source configured with type `soap` pointing to a StUF-BG endpoint (e.g., `https://brp.gemeente.nl/StUF/bg0310`)
- AND the source has valid authentication (mTLS certificate or WS-Security credentials)
- AND the source has stuurgegevens configured (zender: `Procest`, ontvanger: `BRP`)
- WHEN a case handler requests person lookup for BSN `999993653`
- THEN the system MUST construct a StUF-BG `npsLv01` SOAP envelope with the BSN in `gelijk/inp.bsn`
- AND send it to the configured StUF-BG endpoint via OpenConnector's SOAPService
- AND parse the `npsLa01` response extracting: `geslachtsnaam`, `voorvoegselGeslachtsnaam`, `voornamen`, `geboortedatum`, `verblijfsadres`
- AND return the person data as a JSON object to the Procest frontend

#### Scenario STUF-001b: Person not found in BRP

- GIVEN a valid StUF-BG source configuration
- WHEN a lookup is performed for BSN `000000000` (non-existent)
- THEN the StUF-BG service returns a `npsLa01` response with zero results (empty `antwoord`)
- AND the system MUST display: "No person found for BSN 000000000"

#### Scenario STUF-001c: StUF-BG fault handling

- GIVEN a valid StUF-BG source configuration
- WHEN the StUF service returns a `Fo02` fault message (e.g., `StUF055: Niet geautoriseerd`)
- THEN the system MUST parse the fault code and fault string from the `Fo02` envelope
- AND return a structured error: `{ "code": "StUF055", "message": "Niet geautoriseerd", "detail": "..." }`
- AND log the fault at WARNING level

#### Scenario STUF-001d: Look up person by name and date of birth

- GIVEN a valid StUF-BG source configuration
- AND the case handler does not have the BSN but has the person's name and date of birth
- WHEN the handler searches with `geslachtsnaam` = "Moulin" and `geboortedatum` = "19750312"
- THEN the system MUST construct a `npsLv01` with name and date in `gelijk` elements
- AND parse the `npsLa01` response which may contain multiple results
- AND present the results as a selectable list showing BSN, full name, date of birth, and address

#### Scenario STUF-001e: Timeout handling for BRP queries

- GIVEN a valid StUF-BG source configuration with a 10-second timeout
- WHEN the BRP endpoint does not respond within 10 seconds
- THEN the system MUST abort the SOAP call and return an error: "BRP service niet beschikbaar (timeout na 10 seconden)"
- AND log the timeout at WARNING level with the endpoint URL
- AND the case handler MUST be able to continue case processing without the BRP data

---

### REQ-STUF-002: Outbound StUF-ZKN Case Notification

The system MUST support sending case status updates to legacy systems via StUF-ZKN messages. This enables Procest to notify legacy zaaksystemen or DMS systems when case events occur.

**Feature tier**: V1


#### Scenario STUF-002a: Send status update notification

- GIVEN an OpenConnector endpoint configured as type `soap` pointing to a legacy zaaksysteem's StUF-ZKN service
- AND the case "2026-042" has its status changed from "Ontvangen" to "In behandeling"
- AND the case type is configured with a StUF notification endpoint
- WHEN the status change is committed
- THEN the system MUST construct a StUF-ZKN `zakLk01` SOAP envelope with `mutatiesoort="W"` (wijziging)
- AND include the updated zaak data: `identificatie`, `omschrijving`, `status` (with `datumStatusGezet` and status type code)
- AND populate `stuurgegevens` with: `zender` (Procest organization code), `ontvanger` (legacy system code), `referentienummer` (UUID), `tijdstipBericht` (current ISO timestamp)
- AND send the message via OpenConnector's SOAPService

#### Scenario STUF-002b: Send case creation notification

- GIVEN a StUF notification endpoint is configured
- WHEN a new case "2026-043" is created via the Procest UI or ZGW API
- THEN the system MUST construct a StUF-ZKN `zakLk01` SOAP envelope with `mutatiesoort="T"` (toevoeging)
- AND include all initial zaak data including initiator role (`heeftAlsInitiator`)
- AND send the message via OpenConnector

#### Scenario STUF-002c: Handle notification delivery failure

- GIVEN a StUF notification endpoint is configured but unreachable
- WHEN a case status change triggers a notification
- AND the SOAP call fails with a connection timeout
- THEN the system MUST NOT block the status change (the case update proceeds)
- AND the system MUST log the delivery failure at ERROR level
- AND the system SHOULD queue the notification for retry (via OpenConnector's retry mechanism)
- AND the audit trail MUST record: "StUF notification to [endpoint] failed: connection timeout"

#### Scenario STUF-002d: Send case closure notification

- GIVEN a case "2026-042" with status "In behandeling"
- AND a result "Toegekend" is set on the case, triggering automatic status transition to "Afgerond"
- WHEN the closure status is committed
- THEN the system MUST send a `zakLk01` with `mutatiesoort="W"` containing the final status, result, and end date
- AND if an archival action date is set, it MUST be included as `archiefactiedatum`

#### Scenario STUF-002e: Selective notification per case type

- GIVEN case type "Omgevingsvergunning" is configured with StUF notification to endpoint A
- AND case type "Bezwaar" is configured with StUF notification to endpoint B
- AND case type "Melding" has no StUF notification configured
- WHEN a status change occurs on a "Melding" case
- THEN NO StUF notification MUST be sent
- AND the audit trail MUST NOT contain a StUF notification entry

---

### REQ-STUF-003: Outbound StUF-ZKN Document Linking

The system MUST support sending document metadata to legacy DMS systems via StUF-ZKN `edcLk01` messages when documents are uploaded to a case. OpenConnector's SOAPService already has `edcLk01` awareness (base64 content handling).

**Feature tier**: V1


#### Scenario STUF-003a: Notify legacy DMS of new document

- GIVEN a case "2026-042" linked to a StUF-ZKN endpoint
- AND a document "Bouwtekening.pdf" (1.2 MB) is uploaded to the case
- WHEN the document upload is committed
- THEN the system MUST construct a StUF-ZKN `edcLk01` SOAP envelope with `mutatiesoort="T"`
- AND include: `identificatie` (document ID), `titel` ("Bouwtekening.pdf"), `formaat` ("application/pdf"), `inhoud` (base64-encoded content), `vertrouwelijkAanduiding`
- AND include the zaak reference: `isRelevantVoor/gerelateerde/identificatie` = "2026-042"
- AND send via OpenConnector's SOAPService (which already handles `edcLk01` content decoding)

#### Scenario STUF-003b: Large document handling

- GIVEN a document larger than 20 MB
- WHEN the system prepares the `edcLk01` message
- THEN the system SHOULD use MTOM (Message Transmission Optimization Mechanism) for binary content
- OR the system MAY skip the `inhoud` element and include only metadata with a download reference

#### Scenario STUF-003c: Document version update notification

- GIVEN a document "Bouwtekening.pdf" previously sent via `edcLk01` with `mutatiesoort="T"`
- AND the document is replaced with a new version "Bouwtekening_v2.pdf"
- WHEN the document update is committed
- THEN the system MUST send a `edcLk01` with `mutatiesoort="W"` (wijziging)
- AND include the new document content and the same `identificatie` as the original
- AND include `versie` = "2" to indicate the version number

---

### REQ-STUF-004: StUF Stuurgegevens Configuration

The system MUST support configuring StUF `stuurgegevens` (message routing metadata) for each StUF endpoint. Stuurgegevens are mandatory on every StUF message and identify the sender and receiver.

**Feature tier**: V1


#### Scenario STUF-004a: Configure stuurgegevens for a StUF source

- GIVEN an admin configuring a new StUF endpoint in OpenConnector
- WHEN the admin opens the source configuration
- THEN the form MUST include fields for:
  - `zender.organisatie` (sending organization code, e.g., "0363" for Amsterdam)
  - `zender.applicatie` (sending application name, e.g., "Procest")
  - `ontvanger.organisatie` (receiving organization code)
  - `ontvanger.applicatie` (receiving application name)
- AND these values MUST be stored with the source configuration

#### Scenario STUF-004b: Stuurgegevens populated on outbound messages

- GIVEN a StUF source with stuurgegevens configured: zender = `{ organisatie: "0363", applicatie: "Procest" }`, ontvanger = `{ organisatie: "0363", applicatie: "BRP" }`
- WHEN any outbound StUF message is constructed
- THEN the `stuurgegevens` element MUST contain:
  - `zender/organisatie` = "0363"
  - `zender/applicatie` = "Procest"
  - `ontvanger/organisatie` = "0363"
  - `ontvanger/applicatie` = "BRP"
  - `referentienummer` = newly generated UUID
  - `tijdstipBericht` = current timestamp in `YYYYMMDDHHmmss` format

#### Scenario STUF-004c: Cross-reference in response messages

- GIVEN a StUF source sent a message with `referentienummer` = "550e8400-e29b-41d4-a716-446655440000"
- WHEN the external service returns a response (La01, Bv01, or Fo01)
- THEN the response `stuurgegevens` MUST contain `crossRefnummer` matching the original `referentienummer`
- AND the system MUST validate that `crossRefnummer` matches a known outbound `referentienummer`
- AND if no match is found, the response MUST be logged at WARNING level and discarded

---

### REQ-STUF-005: StUF-ZKN zaakIdentificatie Generation

The system SHALL support the `genereerZaakIdentificatie` service call for obtaining zaak identifiers from external systems that manage identifier sequences.

**Feature tier**: V1


#### Scenario STUF-005a: Obtain identifier from legacy system

- GIVEN a StUF-ZKN endpoint that supports `genereerZaakIdentificatie_Di02`
- AND the case type "Omgevingsvergunning" is configured to use external identifier generation
- WHEN a new case of this type is being created
- THEN the system MUST send a `genereerZaakIdentificatie_Di02` message to the configured endpoint
- AND parse the `genereerZaakIdentificatie_Du02` response to extract the `zaakidentificatie`
- AND use this identifier as the case's `identifier` instead of auto-generating one

#### Scenario STUF-005b: Fallback to local generation

- GIVEN a case type configured for external identifier generation
- AND the external StUF endpoint is unreachable
- WHEN a new case is being created
- THEN the system MUST fall back to local identifier generation (format: `YYYY-NNN`)
- AND log a warning: "External zaakidentificatie generation failed, using local identifier"

#### Scenario STUF-005c: Identifier uniqueness validation

- GIVEN a StUF-ZKN endpoint returns identifier "2026-042"
- AND a case with identifier "2026-042" already exists in OpenRegister
- WHEN the system processes the `Du02` response
- THEN the system MUST reject the duplicate identifier
- AND request a new identifier by sending another `Di02` message
- AND if 3 consecutive duplicates are returned, fall back to local generation with a UUID-based identifier and log an ERROR

---

### REQ-STUF-006: Outbound StUF Authentication

The system MUST support the authentication methods required by Dutch government StUF endpoints. OpenConnector's existing certificate handling and AuthenticationService provide the foundation.

**Feature tier**: V1


#### Scenario STUF-006a: mTLS with PKIoverheid certificate

- GIVEN a StUF endpoint requiring PKIoverheid mutual TLS
- AND the admin has uploaded a client certificate and private key in OpenConnector's source configuration
- WHEN the system sends a StUF SOAP message
- THEN the SOAP call MUST include the client certificate for mTLS
- AND the server's certificate MUST be validated against the PKIoverheid certificate chain

#### Scenario STUF-006b: WS-Security UsernameToken

- GIVEN a StUF endpoint requiring WS-Security UsernameToken authentication
- AND the admin has configured username and password in the source configuration
- WHEN the system sends a StUF SOAP message
- THEN the SOAP envelope Header MUST include a `wsse:Security` element with a `wsse:UsernameToken` containing the configured credentials
- AND the password SHOULD be sent as `wsse:Password Type="PasswordDigest"` (nonce + timestamp + password hash)

#### Scenario STUF-006c: Certificate renewal warning

- GIVEN a StUF source with a PKIoverheid client certificate expiring in 30 days
- WHEN an admin views the source configuration
- THEN the system SHOULD display a warning: "Client certificate expires on [date]. Renew before expiry to prevent service interruption."
- AND at 7 days before expiry, a Nextcloud notification SHOULD be sent to all admin users

#### Scenario STUF-006d: Certificate chain validation failure

- GIVEN a StUF endpoint with a server certificate signed by a CA not in the PKIoverheid chain
- WHEN the system attempts to send a SOAP message
- THEN the SOAP call MUST fail with an error: "Server certificate validation failed: unknown CA"
- AND the system MUST NOT send the SOAP message content to an untrusted endpoint
- AND the error MUST be logged at ERROR level with the certificate details

---

### REQ-STUF-007: Inbound StUF-ZKN Case Creation

The system MUST accept incoming StUF-ZKN `zakLk01` messages (with `mutatiesoort="T"`) to create cases from legacy form systems or legacy case systems pushing data to Procest. This is the SOAP server challenge -- Nextcloud routes are REST-based, so the inbound StUF endpoint is implemented as a raw POST handler that parses SOAP XML.

**Feature tier**: V2


#### Scenario STUF-007a: Receive zakLk01 to create a case

- GIVEN a Procest StUF endpoint exposed at `/apps/procest/api/stuf/zaken`
- AND the endpoint accepts `POST` with `Content-Type: text/xml` or `application/soap+xml`
- WHEN a legacy formulierensysteem sends a StUF-ZKN `zakLk01` SOAP message with `mutatiesoort="T"` containing:
  - `omschrijving` = "Aanvraag omgevingsvergunning Dorpsstraat 1"
  - `startdatum` = "20260316"
  - `zaaktype/code` = "OV-001"
  - `heeftAlsInitiator/gerelateerde/inp.bsn` = "999993653"
- THEN the system MUST parse the SOAP envelope and extract the StUF-ZKN message body
- AND map `omschrijving` to case `title`
- AND convert `startdatum` from `YYYYMMDD` to ISO 8601 date
- AND resolve `zaaktype/code` "OV-001" to the matching Procest case type
- AND create an OpenRegister object in the `procest` register with the `case` schema
- AND create a Role object with `genericRole = "initiator"` and BSN `999993653`
- AND auto-calculate the `deadline` from the case type's `processingDeadline`
- AND return a StUF `Bv01` (bevestigingsbericht) with the generated `zaakidentificatie`

#### Scenario STUF-007b: Reject zakLk01 with unknown case type

- GIVEN the StUF endpoint receives a `zakLk01` with `zaaktype/code` = "UNKNOWN-001"
- AND no Procest case type has a matching code
- WHEN the message is processed
- THEN the system MUST return a StUF `Fo01` fault message with:
  - `foutcode` = "StUF058"
  - `foutbeschrijving` = "Onbekend zaaktype: UNKNOWN-001"
  - `plek` = "server"

#### Scenario STUF-007c: Reject invalid XML

- GIVEN the StUF endpoint receives a POST with malformed XML
- WHEN the system attempts to parse the message
- THEN the system MUST return a SOAP Fault with `faultcode = "Client"` and `faultstring = "Ongeldig XML bericht"`

#### Scenario STUF-007d: Validate stuurgegevens on inbound messages

- GIVEN the StUF endpoint is configured with expected `ontvanger` codes
- WHEN a `zakLk01` arrives with `ontvanger/applicatie` = "WrongApp"
- THEN the system MUST return a StUF `Fo01` fault with `foutcode` = "StUF001" and `foutbeschrijving` = "Onbekende ontvanger"

#### Scenario STUF-007e: Case creation with multiple roles

- GIVEN a `zakLk01` with `mutatiesoort="T"` containing:
  - `heeftAlsInitiator/gerelateerde/inp.bsn` = "999993653"
  - `heeftAlsGemachtigde/gerelateerde/vestigingsNummer` = "000012345678"
  - `heeftAlsBelanghebbende/gerelateerde/inp.bsn` = "999990627"
- WHEN the message is processed
- THEN the system MUST create three Role objects: initiator (BSN), gemachtigde (vestigingsnummer), belanghebbende (BSN)
- AND all three roles MUST be linked to the newly created case

---

### REQ-STUF-008: Inbound StUF-ZKN Case Query

The system MUST accept incoming StUF-ZKN `zakLv01` (zaak opvragen) messages and respond with `zakLa01` (zaak antwoord) messages containing case data from OpenRegister.

**Feature tier**: V2


#### Scenario STUF-008a: Query case by identifier

- GIVEN a case "2026-042" exists in the `procest` register with title "Bouwvergunning Keizersgracht 100", status "In behandeling", startDate "2026-01-15"
- WHEN a legacy system sends a `zakLv01` with `gelijk/identificatie` = "2026-042"
- THEN the system MUST return a `zakLa01` SOAP response containing:
  - `identificatie` = "2026-042"
  - `omschrijving` = "Bouwvergunning Keizersgracht 100"
  - `startdatum` = "20260115"
  - Current status with `datumStatusGezet` and status type code
  - Related roles (`heeftAlsInitiator`, `heeftAlsBehandelaar`)
  - Related documents (if `scope` requests them)

#### Scenario STUF-008b: Query with scope filtering

- GIVEN a `zakLv01` request with a `scope` element requesting only `identificatie`, `omschrijving`, and `startdatum`
- WHEN the system processes the request
- THEN the `zakLa01` response MUST include only the requested fields
- AND omitted fields MUST NOT appear in the response (not even as empty elements)

#### Scenario STUF-008c: Query with no results

- GIVEN a `zakLv01` with `gelijk/identificatie` = "9999-999" (non-existent)
- WHEN the system processes the request
- THEN the system MUST return a `zakLa01` with an empty `antwoord` element (zero objects)

#### Scenario STUF-008d: Query with maximumAantal

- GIVEN 50 cases matching the query criteria
- AND the `zakLv01` specifies `maximumAantal` = 10
- WHEN the system processes the request
- THEN the `zakLa01` MUST contain at most 10 zaak objects

#### Scenario STUF-008e: Query cases by date range

- GIVEN 20 cases with `startdatum` between "20260101" and "20260331"
- WHEN a `zakLv01` queries with `van/startdatum` = "20260201" and `totEnMet/startdatum` = "20260228"
- THEN the `zakLa01` MUST contain only cases with `startdatum` in February 2026
- AND the response MUST include `stuurgegevens` with `crossRefnummer` matching the query's `referentienummer`

---

### REQ-STUF-009: Inbound StUF-ZKN Case Update

The system MUST accept incoming StUF-ZKN `zakLk01` messages with `mutatiesoort="W"` (wijziging) to update existing cases.

**Feature tier**: V2


#### Scenario STUF-009a: Update case via zakLk01

- GIVEN a case "2026-042" exists with title "Bouwvergunning Keizersgracht 100"
- WHEN a legacy system sends a `zakLk01` with `mutatiesoort="W"` and `identificatie` = "2026-042" and `omschrijving` = "Bouwvergunning Keizersgracht 100 - gewijzigd"
- THEN the system MUST update the case title to "Bouwvergunning Keizersgracht 100 - gewijzigd"
- AND the audit trail MUST record the update with source "StUF-ZKN"
- AND the system MUST return a `Bv01` bevestiging

#### Scenario STUF-009b: Update case status via zakLk01

- GIVEN a case "2026-042" at status "Ontvangen"
- WHEN a `zakLk01` with `mutatiesoort="W"` includes a new status element with `gpiStatusType/code` = "INBEH" and `datumStatusGezet` = "20260316120000"
- THEN the system MUST resolve "INBEH" to the matching Procest status type
- AND create a new StatusRecord with the specified date
- AND all case type validation rules MUST be enforced (required properties, required documents)

#### Scenario STUF-009c: Reject update for non-existent case

- GIVEN no case with identifier "9999-999" exists
- WHEN a `zakLk01` with `mutatiesoort="W"` and `identificatie` = "9999-999" arrives
- THEN the system MUST return a `Fo01` fault with `foutcode` = "StUF064" and `foutbeschrijving` = "Zaak niet gevonden: 9999-999"

#### Scenario STUF-009d: Partial update with only changed fields

- GIVEN a case "2026-042" with title, description, startDate, and deadline set
- WHEN a `zakLk01` with `mutatiesoort="W"` includes only `toelichting` = "Aangepaste toelichting"
- THEN the system MUST update only the `description` field
- AND all other fields (title, startDate, deadline) MUST remain unchanged
- AND the `Bv01` response MUST confirm the update

#### Scenario STUF-009e: Reject update on closed case

- GIVEN a case "2026-042" with status "Afgerond" (closed)
- WHEN a `zakLk01` with `mutatiesoort="W"` attempts to change the `omschrijving`
- THEN the system MUST enforce the `ZgwZrcRulesService` immutability rules
- AND return a `Fo01` fault with `foutcode` = "StUF062" and `foutbeschrijving` = "Zaak is afgesloten en kan niet meer worden gewijzigd"

---

### REQ-STUF-010: Inbound StUF-ZKN Document Handling

The system MUST accept incoming StUF-ZKN `edcLk01` messages to link documents to cases.

**Feature tier**: V2


#### Scenario STUF-010a: Receive document via edcLk01

- GIVEN a case "2026-042" exists
- WHEN a legacy system sends an `edcLk01` with `mutatiesoort="T"` containing:
  - `identificatie` = "DOC-2026-001"
  - `titel` = "Bouwtekening.pdf"
  - `formaat` = "application/pdf"
  - `inhoud` = base64-encoded PDF content
  - `isRelevantVoor/gerelateerde/identificatie` = "2026-042"
- THEN the system MUST decode the base64 `inhoud`
- AND store the document in Nextcloud Files under the case's folder
- AND create a caseDocument object linking the document to the case
- AND return a `Bv01` bevestiging

#### Scenario STUF-010b: Document without content (metadata only)

- GIVEN an `edcLk01` with document metadata but no `inhoud` element
- WHEN the message is processed
- THEN the system MUST create a caseDocument object with the metadata
- AND mark the document as "metadata only -- no content received"

#### Scenario STUF-010c: Document linked to non-existent case

- GIVEN an `edcLk01` with `isRelevantVoor/gerelateerde/identificatie` = "9999-999"
- AND no case with that identifier exists
- WHEN the message is processed
- THEN the system MUST return a `Fo01` fault with `foutcode` = "StUF064" and `foutbeschrijving` = "Zaak niet gevonden: 9999-999"

---

### REQ-STUF-011: StUF XML Message Processing

The system MUST correctly handle StUF XML namespaces, date formats, noValue attributes, and message structure.

**Feature tier**: V1 (outbound), V2 (inbound)


#### Scenario STUF-011a: XML namespace handling

- GIVEN a StUF-ZKN message is being constructed or parsed
- THEN the system MUST correctly handle these namespaces:
  - `http://www.egem.nl/StUF/StUF0301` (StUF base)
  - `http://www.egem.nl/StUF/sector/zkn/0310` (StUF-ZKN)
  - `http://www.egem.nl/StUF/sector/bg/0310` (StUF-BG)
  - `http://www.w3.org/2001/XMLSchema-instance` (xsi)
  - `http://www.opengis.net/gml` (gml, for geometry)

#### Scenario STUF-011b: Date format conversion

- GIVEN a date value "2026-03-16" in ISO 8601 format (Procest internal)
- WHEN the value is included in an outbound StUF message
- THEN the value MUST be converted to StUF format: "20260316"
- AND datetime values MUST use format: "YYYYMMDDHHmmss" (e.g., "20260316143000")

#### Scenario STUF-011c: noValue attribute handling

- GIVEN a case property that has no value (null/empty)
- WHEN the property is included in an outbound StUF message
- THEN the element MUST include the appropriate `StUF:noValue` attribute:
  - `geenWaarde` -- explicitly set to no value
  - `waardeOnbekend` -- value exists but is unknown
  - `nietOndersteund` -- field not supported by the system
  - `vastgesteldOnbekend` -- officially determined as unknown

#### Scenario STUF-011d: Validate outbound XML against XSD

- GIVEN bundled StUF-ZKN 3.10 and StUF-BG 3.10 XSD schemas
- WHEN an outbound StUF message is constructed
- THEN the system SHOULD validate the XML against the relevant XSD before sending
- AND if validation fails, the system MUST log the validation errors and NOT send the invalid message

#### Scenario STUF-011e: Handle XML entities and special characters

- GIVEN a case title containing special characters: `Vergunning "Café & Bar" <Centrum>`
- WHEN the title is included in an outbound StUF message
- THEN XML entities MUST be properly escaped: `&amp;`, `&lt;`, `&gt;`, `&quot;`
- AND the resulting XML MUST be well-formed and parseable

---

### REQ-STUF-012: StUF-BG Inbound Person Query

The system SHALL accept incoming StUF-BG `npsLv01` messages to expose person data stored in OpenRegister. This enables legacy systems to query Procest as if it were a BRP source.

**Feature tier**: V2


#### Scenario STUF-012a: Receive person query

- GIVEN the Procest StUF endpoint at `/apps/procest/api/stuf/personen`
- AND a person object with BSN "999993653" exists in OpenRegister
- WHEN a legacy system sends a StUF-BG `npsLv01` with `gelijk/inp.bsn` = "999993653"
- THEN the system MUST return a `npsLa01` response with the person data mapped from OpenRegister to StUF-BG XML

#### Scenario STUF-012b: Person query with scope

- GIVEN a `npsLv01` with scope requesting only `inp.bsn`, `geslachtsnaam`, and `geboortedatum`
- WHEN the system processes the request
- THEN the `npsLa01` MUST include only the requested fields

#### Scenario STUF-012c: Person query with wildcard search

- GIVEN 5 persons in OpenRegister with `geslachtsnaam` starting with "Jan"
- WHEN a `npsLv01` queries with `gelijk/geslachtsnaam` = "Jan*" (wildcard)
- THEN the system MUST return all 5 matching persons in the `npsLa01` response
- AND results MUST be ordered by `geslachtsnaam` ascending

---

### REQ-STUF-013: StUF Field Mapping Configuration

The system MUST store field mappings between StUF XML paths and OpenRegister object properties as configurable mapping objects. Default mappings for ZGW-zaak and BRP-person data MUST be pre-seeded.

**Feature tier**: V1


#### Scenario STUF-013a: Pre-seeded zaak field mapping

- GIVEN the Procest app is installed and the repair step runs
- THEN a default StUF-ZKN field mapping MUST be created in OpenRegister containing mappings for all fields listed in the "StUF-ZKN to Procest Case Mapping" table above
- AND the mapping MUST include date format transformations (StUF `YYYYMMDD` to ISO 8601 and vice versa)

#### Scenario STUF-013b: Custom field mapping

- GIVEN a municipality uses a StUF extension with custom fields (e.g., `gem:kenmerk` for a local reference number)
- WHEN an admin adds a custom mapping entry: StUF path `gem:kenmerk` -> OpenRegister property `localReference`
- THEN the system MUST apply this mapping during StUF message parsing and construction
- AND the custom mapping MUST NOT override default mappings unless explicitly configured

#### Scenario STUF-013c: Value transformation in mapping

- GIVEN a mapping entry for `vertrouwelijkAanduiding` with a value transformation table (StUF enum values to Procest enum values)
- WHEN a StUF message contains `vertrouwelijkAanduiding` = "ZAAKVERTROUWELIJK"
- THEN the system MUST transform the value to `case_sensitive` using the mapping's transformation table

#### Scenario STUF-013d: Mapping object schema structure

- GIVEN the StUF field mapping is stored as an OpenRegister object
- THEN the mapping schema MUST include:
  - `name` (string): mapping set name (e.g., "StUF-ZKN to Procest Case")
  - `sourceFormat` (enum): "stuf-zkn" | "stuf-bg"
  - `targetSchema` (string): OpenRegister schema reference (e.g., "case", "person")
  - `fieldMappings` (array): each entry with `{ stufPath, openRegisterProperty, dataType, transformation, required }`
  - `dateFormat` (string): date format pattern for this mapping set
  - `isDefault` (boolean): whether this is a system-provided mapping

#### Scenario STUF-013e: Export and import mapping configurations

- GIVEN an admin who configured custom StUF mappings for municipality A
- WHEN the admin exports the mapping configuration
- THEN the system MUST produce a JSON file containing all custom mapping entries
- AND the exported file MUST be importable on another Procest instance for municipality B

---

### REQ-STUF-014: SOAP Server Within Nextcloud

The system SHALL provide a SOAP server within Nextcloud for exposing inbound StUF endpoints. Since Nextcloud routes are REST-based, the StUF controller accepts raw XML POSTs and processes them as SOAP messages without using PHP's built-in SoapServer (which requires WSDL mode and conflicts with Nextcloud's routing).

**Feature tier**: V2


#### Scenario STUF-014a: Raw SOAP POST handling

- GIVEN a Procest route `/apps/procest/api/stuf/{service}` registered as a raw POST endpoint
- WHEN a SOAP message arrives with `Content-Type: text/xml; charset=utf-8`
- THEN the controller MUST read the raw request body (`php://input`)
- AND parse the SOAP envelope using PHP's DOMDocument or SimpleXML
- AND extract the SOAP Body content
- AND dispatch to the appropriate handler based on the root element name (e.g., `zakLk01`, `zakLv01`, `npsLv01`)
- AND construct a SOAP envelope response with the appropriate content
- AND return with `Content-Type: text/xml; charset=utf-8`

#### Scenario STUF-014b: WSDL serving

- GIVEN bundled WSDL files for StUF-ZKN and StUF-BG services
- WHEN a client sends a GET request to `/apps/procest/api/stuf/zaken?wsdl`
- THEN the system MUST return the StUF-ZKN WSDL file with `Content-Type: text/xml`
- AND the WSDL's `soap:address location` MUST reflect the actual Procest endpoint URL

#### Scenario STUF-014c: SOAPAction header routing

- GIVEN a SOAP request with `SOAPAction: "http://www.egem.nl/StUF/sector/zkn/0310/zakLk01"`
- WHEN the controller processes the request
- THEN the SOAPAction header MAY be used as a secondary dispatch mechanism alongside XML body inspection

#### Scenario STUF-014d: Request logging for audit compliance

- GIVEN the Procest StUF endpoint receives a SOAP message
- THEN the system MUST log: timestamp, source IP, SOAPAction header, message type (zakLk01/zakLv01/etc.), stuurgegevens (zender/ontvanger), processing result (success/fault)
- AND the raw XML MUST be stored in a separate audit log (configurable retention period)
- AND sensitive data (BSN, personal names) MUST be masked in standard log output but preserved in the audit log

---

### REQ-STUF-015: Dual API Coexistence

The system MUST ensure that StUF and ZGW APIs provide consistent views of the same data. Changes made via one protocol MUST be immediately visible via the other.

**Feature tier**: V1


#### Scenario STUF-015a: Case created via StUF visible in ZGW

- GIVEN a case created via inbound StUF-ZKN `zakLk01` with identifier "2026-042"
- WHEN a ZGW API client sends GET `/api/v1/zaken?identificatie=2026-042` to the ZrcController
- THEN the case MUST be returned with all fields populated from the StUF-created data
- AND the `url` field MUST contain the ZGW-style self-link

#### Scenario STUF-015b: Case created via ZGW queryable via StUF

- GIVEN a case created via the ZGW Zaken API with identifier "2026-043"
- WHEN a legacy system sends a StUF-ZKN `zakLv01` with `gelijk/identificatie` = "2026-043"
- THEN the case MUST be returned in the `zakLa01` response with all fields correctly mapped to StUF-ZKN XML

#### Scenario STUF-015c: Case updated via UI reflected in StUF query

- GIVEN a case "2026-042" with status "Ontvangen"
- WHEN a handler changes the status to "In behandeling" via the Procest frontend
- AND a legacy system immediately sends a `zakLv01` for "2026-042"
- THEN the response MUST show the new status "In behandeling" with the correct `datumStatusGezet`

#### Scenario STUF-015d: StUF and ZGW audit trail unification

- GIVEN a case "2026-042" that was:
  1. Created via StUF-ZKN `zakLk01`
  2. Updated via ZGW Zaken API
  3. Queried via StUF-ZKN `zakLv01`
- WHEN viewing the case audit trail in the Procest frontend
- THEN all three actions MUST appear in chronological order
- AND each entry MUST show the protocol used ("StUF-ZKN", "ZGW API", "Frontend")
- AND the audit trail MUST be queryable by protocol type

---

## SOAP Server Challenge

Hosting a SOAP server within Nextcloud presents architectural challenges:

1. **Nextcloud routing is REST-based**: All routes go through `routes.php` and expect JSON request/response. StUF requires raw XML POST handling with SOAP envelope wrapping.

2. **PHP SoapServer limitations**: PHP's built-in `SoapServer` class operates in WSDL mode and expects to handle the full HTTP lifecycle. Within Nextcloud's controller framework, this conflicts with the existing request/response handling.

3. **Proposed solution**: Implement a `StufController` that extends `OCP\AppFramework\Controller` and:
   - Registers routes for `/api/stuf/zaken` and `/api/stuf/personen`
   - Reads raw XML from `php://input`
   - Parses XML using DOMDocument (not SoapServer)
   - Dispatches to a `StufMessageHandler` service
   - Constructs SOAP XML responses manually
   - Returns `DataDisplayResponse` with `Content-Type: text/xml`

4. **Alternative approach**: Use OpenConnector as a SOAP proxy -- OpenConnector could host the SOAP endpoint (it already has SOAPService) and forward parsed data to Procest's REST API. This avoids the SOAP-in-Nextcloud problem but adds a network hop and dependency.

---

## Dependencies

- **OpenConnector stuf-adapter spec** (`openconnector/openspec/specs/stuf-adapter/spec.md`): Provides the SOAP infrastructure (SOAPService), certificate handling, and source type configuration. Procest leverages OpenConnector for all outbound StUF communication.
- **OpenRegister**: All case data stored as objects; field mapping configurations stored as OpenRegister objects.
- **Procest case-management spec** (`../case-management/spec.md`): The case data model, status lifecycle, validation rules, and audit trail that StUF messages map to/from.
- **Procest roles-decisions spec** (`../roles-decisions/spec.md`): Role entities created from StUF `heeftAlsInitiator`/`heeftAlsBehandelaar` data.
- **Procest openregister-integration spec** (`../openregister-integration/spec.md`): The `procest` register and 12 schemas that store all data.
- **PHP DOMDocument / SimpleXML**: For XML parsing and construction (bundled with PHP).
- **StUF XSD schema packages**: StUF-BG 3.10 and StUF-ZKN 3.10 XSD files for validation.
- **Existing ZGW controllers** (`ZrcController`, `ZtcController`, `DrcController`, `BrcController`): The REST API surface that coexists with StUF.

---

## Current Implementation Status

### Using Mock Register Data

This spec depends on the **BRP** mock register for testing StUF-BG person lookup (REQ-STUF-001) and the **BAG** mock register for address data.

**Loading the registers:**
```bash
# Load BRP register (35 persons, register slug: "brp", schema: "ingeschreven-persoon")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json

# Load BAG register (32 addresses + 21 objects + 21 buildings, register slug: "bag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
```

**Test data for this spec's use cases:**
- **StUF-BG npsLv01 person lookup**: BSN `999993653` (Suzanne Moulin) -- test outbound BRP query and npsLa01 response mapping
- **StUF-BG person not found**: BSN `000000000` -- test empty npsLa01 response handling
- **StUF-ZKN with initiator BSN**: BSN `999990627` (Stephan Janssen) -- test inbound zakLk01 with `heeftAlsInitiator/inp.bsn`
- **StUF-BG inbound query (REQ-STUF-012)**: BSN `999992570` (Albert Vogel) -- test serving person data via StUF-BG endpoint

### Procest

**No StUF implementation exists.** Grep for "stuf", "StUF", and "soap" in `procest/lib/` returns zero results. All current API communication is via ZGW REST APIs through the four ZGW controllers.

**Implemented (ZGW foundation that StUF maps to):**
- ZGW Zaken API via `ZrcController` -- zaken, statussen, resultaten, rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten
- ZGW Catalogi API via `ZtcController` -- zaaktypen, statustypen, resultaattypen, roltypen, besluittypen
- ZGW Documenten API via `DrcController` -- enkelvoudiginformatieobjecten
- ZGW Besluiten API via `BrcController` -- besluiten
- ZGW business rules via `ZgwBusinessRulesService` and `ZgwZrcRulesService`
- OpenRegister schemas for all 12 entity types

### OpenConnector

**Partial SOAP infrastructure exists** (see `openconnector/openspec/specs/stuf-adapter/spec.md` for details):
- `SOAPService` with generic SOAP client, WSDL-driven requests, SOAP 1.1/1.2 support
- Specific StUF-ZKN `edcLk01` handling (base64 document content decoding)
- Source type `soap` with WSDL URL and authentication configuration
- Certificate handling for mTLS (PKIoverheid)
- `CallService` SOAP routing (type `soap` -> SOAPService)
- **NOT implemented in OpenConnector**: Inbound SOAP server, StUF field mappings, WSDL bundling, stuurgegevens, namespace handling, noValue attributes, fault message handling (Fo01/Fo02/Fo03/Bv03)

---

## Standards & References

- **StUF 3.01**: Standaard Uitwisseling Formaat, base standard. Defines the SOAP message structure, stuurgegevens, kennisgevingen (Lk01), vraag/antwoord (Lv01/La01), bevestiging (Bv01/Bv03), and foutmeldingen (Fo01/Fo02/Fo03). Maintained by VNG Realisatie. https://www.gemmaonline.nl/index.php/StUF_Berichtenstandaard
- **StUF-ZKN 3.10**: Sectormodel Zaak-/Documentservices, built on StUF 3.01. Defines zaak (zak), document (edc), status, and related message types. The "e" extension (3.10e) adds extra message types. https://www.gemmaonline.nl/index.php/Sectormodel_Zaken:_StUF-ZKN
- **StUF-BG 3.10**: Sectormodel Basisgegevens, built on StUF 3.01. Defines person (nps), address (adr), and other base registry data types. https://www.gemmaonline.nl/index.php/Sectormodel_Basisgegevens:_StUF-BG
- **RGBZ 2.0 (Referentiemodel Gemeentelijke Basisgegevens Zaken)**: The information model underlying StUF-ZKN, defining zaak, status, document, besluit, and their relationships. The same model underlies ZGW APIs.
- **ZGW APIs (VNG)**: The modern REST-based successor to StUF-ZKN. Procest already implements these via ZrcController, ZtcController, DrcController, BrcController. https://vng-realisatie.github.io/gemma-zaken/
- **GEMMA**: Gemeentelijke Model Architectuur, the reference architecture for Dutch municipalities. Defines how StUF and ZGW fit in the municipal information landscape. https://www.gemmaonline.nl/
- **Logius**: Dutch government IT authority responsible for PKIoverheid certificates and DigiKoppeling (the transport standard for government-to-government communication, which mandates how StUF messages are exchanged).
- **DigiKoppeling**: Transport standard (WUS/ebMS) for Dutch government interoperability. StUF messages are typically exchanged over DigiKoppeling WUS (SOAP+WS-Security). https://www.logius.nl/domeinen/gegevensuitwisseling/digikoppeling
- **WS-Security (OASIS)**: SOAP message security standard. UsernameToken and X.509 Token profiles are used by Dutch government StUF endpoints.
- **PKIoverheid**: Dutch government PKI for mTLS authentication on production StUF endpoints.
- **MTOM (Message Transmission Optimization Mechanism)**: W3C standard for efficient binary content in SOAP messages, relevant for large document transfers via edcLk01.

---

## Specificity Assessment

### Sufficient for implementation
- Complete field mapping tables between StUF-ZKN/BG and Procest case model
- Clear separation of outbound (V1, via OpenConnector) and inbound (V2, SOAP server) concerns
- Detailed Gherkin scenarios for each message type with concrete data examples
- Explicit SOAP server architecture proposal addressing the Nextcloud routing challenge
- Coexistence principle ensuring data consistency between StUF and ZGW APIs
- Authentication methods (mTLS, WS-Security) aligned with existing OpenConnector capabilities
- Reference to OpenConnector's existing SOAPService and edcLk01 handling

### Missing or ambiguous
- **StUF version negotiation**: The spec targets 3.01/3.10 but some municipalities may run older versions. Version detection and fallback behavior is not defined.
- **Async response patterns**: StUF supports asynchronous Bv03/Fo03 callback patterns for long-running operations. The callback mechanism (how Procest receives async responses) is not detailed.
- **Performance requirements**: No throughput or latency SLAs for SOAP message processing.
- **Mapping object schema**: REQ-STUF-013 now defines the mapping schema structure in scenario STUF-013d.
- **Multi-source routing**: Can multiple StUF endpoints be configured for different case types, or is there one global StUF endpoint?
- **StUF-ZKN 3.10e extensions**: The "e" extension adds extra message types not covered in this spec.
- **Archival-related StUF messages**: StUF defines messages for archival transfers (overbrenging) which relate to the case result archival rules but are not covered here.
- **Rate limiting and access control**: How are inbound StUF endpoints secured beyond stuurgegevens validation? IP whitelisting? Client certificate validation on the inbound side?

### Open questions
1. Should inbound StUF endpoints be hosted in Procest directly or proxied via OpenConnector (which already has SOAPService)?
2. Which StUF version(s) must be supported on day one -- 3.01 only, 3.10 only, or both?
3. Should the field mapping configuration be stored in the `procest` register or in OpenConnector's register?
4. How should large document content in inbound `edcLk01` messages be handled -- streamed to disk or loaded into memory?
5. Is DigiKoppeling (WUS profile) compliance required, or is plain HTTPS with WS-Security sufficient?
