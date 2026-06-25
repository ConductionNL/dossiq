---
status: done
retrofit: true
---

# StUF Integration Specification

## Purpose

@e2e exclude Pure SOAP/backend integration spec covered by PHPUnit; no Playwright UI surface.

Accept inbound StUF (Standaard Uitwisselings Formaat) 3.01 SOAP messages from legacy Dutch government back-office systems, dispatch them by message type to dedicated handlers, map ZKN/BG fields to OpenRegister object properties (and back), and respond with well-formed StUF SOAP envelopes — so that Procest can interoperate with legacy zaaksystemen, BRP-clients and document systems without the producing system having to migrate to REST.

## Requirements

### REQ-001: Inbound StUF SOAP routing + message-type dispatch

The system SHALL expose `/api/stuf/{service}` endpoints (`zaken`, `personen`) on `StufController` that accept raw XML POST, parse the SOAP envelope, extract the first StUF message child of `soap:Body`, log it, and dispatch by `localName` to the matching private handler (`zakLk01` / `zakLv01` / `npsLv01` / `edcLk01`) or return a SOAP `Fo01` fault for unknown types.

#### Scenario: Empty or malformed body

- WHEN the controller receives an empty body or fails to parse XML (libxml errors)
- THEN it SHALL return a SOAP fault envelope with HTTP 400 (`'Leeg bericht ontvangen'` / `'Ongeldig XML bericht'`)

#### Scenario: Missing SOAP envelope structure

- WHEN the request body parses but lacks a `soap:Body` element, has an empty body, or has no child element under body
- THEN the controller SHALL return a `buildSoapFault` envelope with HTTP 400 and a descriptive message in Dutch

#### Scenario: Dispatch by message type

- WHEN the body contains a valid StUF message
- THEN the controller SHALL log `'Received StUF message: {type} at {service}'` and dispatch via PHP `match` to the handler for that `localName`

#### Notes

- Both endpoints (`zaken`, `personen`) share the same dispatcher — the `{service}` segment is logged but does not constrain which message types are accepted.
- Unknown message types route to `handleUnknownMessage` which returns `Fo01` with code `StUF001` and HTTP 400.

### REQ-002: StUF-ZKN case handlers (zakLk01, zakLv01, edcLk01)

The system SHALL handle StUF-ZKN case-lifecycle messages: `zakLk01` (create/update) extracts standard case fields, maps them to internal properties, captures `mutatiesoort` + `referentienummer`, and returns a `Bv01` confirmation; `zakLv01` (query) extracts criteria from the `gelijk` element and returns an empty `zakLa01` answer envelope; `edcLk01` (document message) returns a `Bv01` confirmation with the inbound `referentienummer`.

#### Scenario: zakLk01 create/update

- WHEN a `zakLk01` arrives
- THEN the controller SHALL extract `identificatie`, `omschrijving`, `toelichting`, `startdatum`, `einddatum`, `einddatumGepland`, `uiterlijkeEinddatumAfdoening`, `vertrouwelijkAanduiding` from the first `object` element
- AND call `StufFieldMappingService::mapZknToInternal` to convert to OpenRegister property names
- AND respond with `buildBv01(zender, [], crossRef)` where `crossRef` is the inbound `stuurgegevens/referentienummer`
- AND if no `object` element is present, respond with `buildFo01('StUF055', 'Geen object element in zakLk01', 'server', zender, [])`

#### Scenario: zakLv01 query

- WHEN a `zakLv01` arrives
- THEN the controller SHALL extract `identificatie`, `omschrijving`, `startdatum` from the first `gelijk` element
- AND respond with a SOAP envelope wrapping `<zkn:zakLa01>` containing `stuurgegevens` and an empty `<zkn:antwoord/>`

#### Scenario: edcLk01 document

- WHEN an `edcLk01` arrives
- THEN the controller SHALL respond with `buildBv01(zender, [], crossRef)` using the inbound `referentienummer`

#### Notes

- Persistence to OpenRegister is observed-but-stubbed (`// In a full implementation, create/update OpenRegister objects here`). The handler logs the mapped data and returns success without writing.
- `zakLv01` always returns an empty `<antwoord/>` — OpenRegister query is observed-but-stubbed.

### REQ-003: StUF-BG person query handler (npsLv01)

The system SHALL handle `npsLv01` (person-by-BSN query) by extracting the BSN from `gelijk/bsn`, logging the first 3 digits with the rest masked (`BSN abc***`), and returning an empty `<bg:npsLa01>` envelope.

#### Scenario: BSN extraction and PII-safe logging

- WHEN an `npsLv01` arrives
- THEN the controller SHALL extract the BSN from the first `gelijk` element's `bsn` child
- AND log `'Processed npsLv01 person query for BSN {bsn}'` with `substr($bsn, 0, 3) . '***'` to redact the rest

#### Scenario: Response envelope

- WHEN the handler completes
- THEN it SHALL respond with a SOAP envelope wrapping `<bg:npsLa01>` containing `stuurgegevens` and an empty `<bg:antwoord/>`

#### Notes

- OpenRegister person lookup is observed-but-stubbed; the handler always returns the empty `<antwoord/>` placeholder.

### REQ-004: Bidirectional StUF↔OpenRegister field mapping with date and enum transforms

The system SHALL provide a `StufFieldMappingService` that maps StUF-ZKN field names (`identificatie`, `omschrijving`, `startdatum`, ...) and StUF-BG field names (`inp.bsn`, `geslachtsnaam`, `geboortedatum`, ...) to OpenRegister property names, applying transforms for StUF `Ymd`/`YmdHis` dates (↔ ISO 8601) and the `vertrouwelijkAanduiding` enum (OPENBAAR → public, GEHEIM → secret, etc.), with support for runtime custom-mapping override.

#### Scenario: Default ZKN mapping

- WHEN `mapZknToInternal` is called with `{identificatie, omschrijving, startdatum: '20240315'}`
- THEN it SHALL return `{identifier, title, startDate: '2024-03-15...'}` applying `stufDateToIso` to date-typed fields

#### Scenario: Default BG mapping

- WHEN `mapBgToInternal` is called with `{'inp.bsn', geslachtsnaam, voornamen, geboortedatum}`
- THEN it SHALL return `{bsn, lastName, firstName, dateOfBirth (ISO 8601)}`

#### Scenario: Confidentiality enum

- WHEN a StUF field maps with the `confidentialityToInternal` transform
- THEN values map per the table OPENBAAR→public, `BEPERKT OPENBAAR`→restricted, INTERN→internal, ZAAKVERTROUWELIJK→case_sensitive, VERTROUWELIJK→confidential, CONFIDENTIEEL→highly_confidential, GEHEIM→secret, `ZEER GEHEIM`→top_secret

#### Scenario: Custom mapping override

- WHEN custom mappings have been loaded for a sector
- THEN they SHALL take precedence over the corresponding default mapping for that sector

#### Notes

- Date format constants are `Ymd` (date) and `YmdHis` (datetime); mapping is bidirectional via paired `*ToStuf`/`*ToInternal` methods.

### REQ-005: StUF SOAP envelope construction with namespaces, stuurgegevens, noValue

The system SHALL provide a `StufMessageBuilder` that constructs StUF SOAP envelopes with the four canonical namespaces (`NS_STUF`, `NS_ZKN`, `NS_BG`, `NS_SOAP`, `NS_XSI`), builds `stuurgegevens` blocks from zender/ontvanger arrays, builds `Bv01` confirmation and `Fo01` fault payloads, and produces `noValue`-attribute markers for the four StUF "absence" types.

#### Scenario: SOAP envelope wrapping

- WHEN `buildSoapEnvelope(bodyXml)` is called
- THEN the result SHALL be a UTF-8 XML document with `soap:Envelope` declaring `stuf`, `zkn`, `bg`, `xsi` namespaces, an empty `soap:Header`, and a `soap:Body` containing the imported body element

#### Scenario: Fo01 fault payload

- WHEN `buildFo01(code, omschrijving, plek, zender, ontvanger)` is called
- THEN the result SHALL be a SOAP envelope wrapping a StUF `Fo01` payload carrying the fault `code`, human-readable `omschrijving`, fault `plek` (server/client), and stuurgegevens

#### Scenario: noValue attribute set

- WHEN the builder is asked to emit a noValue marker
- THEN it SHALL pick from `NO_VALUE_TYPES` exactly one of `geenWaarde`, `waardeOnbekend`, `nietOndersteund`, `vastgesteldOnbekend`

#### Notes

- All namespace constants are public (`StufController` reads `StufMessageBuilder::NS_SOAP` directly to find `soap:Body`); callers depend on them as part of the public API.
