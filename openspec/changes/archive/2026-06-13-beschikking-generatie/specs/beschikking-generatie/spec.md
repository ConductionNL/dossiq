---
status: draft
---

# Beschikking compose → ondertekenen → Berichtenbox → archief — Specification Delta

## ADDED Requirements

### Requirement: Conceptbeschikking vanuit zaakgegevens samenstellen (REQ-BES-001)

The system SHALL compose a conceptbeschikking from a template (Docudesk) and the current zaakdata (Procest), with all required fields prepopulated and missing required fields explicitly marked.

**Feature tier**: V1

#### Scenario: Conceptbeschikking is generated with prefilled fields

- **GIVEN** a WMO case with completed indicatiestelling and the handler clicks "beschikking opstellen"
- **WHEN** the system applies the template `tpl-wmo-toekenning-huishoudelijke-hulp-v4`
- **THEN** a conceptbeschikking SHALL be generated with `geadresseerde`, `omvang`, `ingangsdatum`, and `motivering` automatically filled from case data and indicatiestelling
- **AND** the HTTP response SHALL include the full Beschikking object with `huidigeStatus: ontwerp`

#### Scenario: Missing required fields are marked and block progression

- **GIVEN** a required field in the template cannot be filled from zaak data (e.g., motivering must be entered by hand)
- **WHEN** the conceptbeschikking is displayed
- **THEN** the missing field SHALL be visually marked as `_required: true` in the composed beschikking
- **AND** the system SHALL reject any state transition beyond `ontwerp` until the field is manually filled
- **AND** the API response code for a PATCH to `akkoord` without the field SHALL be HTTP 400 with a specific error message listing the missing field

#### Scenario: Composition request includes zaakId and returns templated PDF preview

- **GIVEN** the handler requests composition via `POST /api/beschikkingen` with `zaakId` and optional template overrides
- **WHEN** the request is processed
- **THEN** the system SHALL call Docudesk to render the template, passing all zaakdata as context
- **AND** the response SHALL include `samengesteldeInhoud.bestandId` (Nextcloud file ID), `checksumSha256`, and `paginas` count
- **AND** the system SHALL store the bestand in Nextcloud linked to the case

### Requirement: Mandaatverificatie for akkoordstap (REQ-BES-002)

Before a beschikking can transition from `ontwerp` to `akkoord-mandaat`, the system SHALL verify that the chosen approver is authorized under the current mandaatregeling for the beschikkingType and bedrag.

**Feature tier**: V1

#### Scenario: Non-authorized mandaatlevel is rejected

- **GIVEN** a beschikking for a €18,000 WMO toekenning
- **WHEN** a handler selects a consulent (mandaat-limit €5,000) as akkoordgever
- **THEN** the system SHALL reject the PATCH to `akkoord` with HTTP 403
- **AND** the response SHALL include a message that an afdelingsmanager (€25,000 limit) or directeur is required

#### Scenario: Authorized mandaatlevel is accepted and recorded

- **GIVEN** an afdelingsmanager with valid mandaat
- **WHEN** the handler submits PATCH `/api/beschikkingen/{id}/akkoord` with `akkoordDoor: afdelingsmanager-wmo-15`
- **THEN** the system SHALL verify the mandaat via the geldende mandaatregeling
- **AND** the state SHALL transition to `akkoord-mandaat`
- **AND** `mandaatGegeven` SHALL be recorded with regeling-id, niveau, actor, and timestamp
- **AND** a `StateMachineLog` entry SHALL be created

### Requirement: eIDAS-gekwalificeerde elektronische handtekening (REQ-BES-003)

Signing of a beschikking SHALL occur via an eIDAS-qualified Trust Service Provider (TSP), and the signing result SHALL produce a durably stored validation report.

**Feature tier**: V1

#### Scenario: Valid signature is recorded and beschikking transitions to ondertekend

- **GIVEN** the beschikking has status `akkoord-mandaat` and the handler initiates signing
- **WHEN** the TSP flow is invoked via OpenConnector
- **THEN** the handler SHALL authenticate with the TSP (e.g., via KPN or EvidosSign)
- **AND** the signed PDF bytes SHALL be returned with a `validatieRapportId`
- **AND** the system SHALL store the signed PDF in Nextcloud and link it to the beschikking
- **AND** `handtekening.ondertekeningTijdstip`, `certificaatSerienummer`, and `validatieRapportId` SHALL be recorded
- **AND** the state SHALL transition to `ondertekend`
- **AND** a `StateMachineLog` entry SHALL be created with `bewijsMateriaal.soort: tsp-handtekening-rapport`

#### Scenario: Invalid signature blocks transition

- **GIVEN** the TSP returns an invalid or expired certificate
- **WHEN** the system processes the TSP response
- **THEN** the state SHALL remain `akkoord-mandaat` (no transition)
- **AND** the error SHALL be logged with a warning to the handler
- **AND** the handler SHALL be prompted to retry

### Requirement: Berichtenbox-aanlevering met kanaalkeuze burger/bedrijf (REQ-BES-004)

Delivery of a signed beschikking to the addressee SHALL occur via the correct Berichtenbox channel: MijnOverheid for burgers (BSN), eHerkenning OIN for businesses, or print-post as fallback.

**Feature tier**: V1

#### Scenario: Burger with MijnOverheid is delivered via MijnOverheid

- **GIVEN** the addressee is a burger with BSN and MijnOverheid Berichtenbox activated
- **WHEN** the handler initiates delivery via PATCH `/api/beschikkingen/{id}/verzend`
- **THEN** OpenConnector SHALL submit the beschikking to the MijnOverheid API
- **AND** `verzending.berichtId` SHALL be recorded from the MijnOverheid response
- **AND** `verzending.verzondenOp` SHALL be set to the current timestamp
- **AND** the state SHALL transition to `verzonden`

#### Scenario: Bedrijf with OIN is delivered via eHerkenning

- **GIVEN** the addressee is a bedrijf with OIN
- **WHEN** delivery is initiated
- **THEN** OpenConnector SHALL submit the beschikking to the eHerkenning OIN Berichtenbox endpoint
- **AND** `verzending.berichtId` and `verzending.verzondenOp` SHALL be recorded

#### Scenario: No Berichtenbox → print-post fallback

- **GIVEN** the addressee has no Berichtenbox activated
- **WHEN** delivery is initiated
- **THEN** the system SHALL detect the absence and mark the beschikking for print-post
- **AND** a print-job SHALL be created for the postkamer
- **AND** `verzending.kanaal` SHALL be set to `print-post`
- **AND** the state SHALL transition to `verzonden`

### Requirement: State-machine voor beschikkingsstatus (REQ-BES-005)

The system SHALL enforce a formal state-machine with the sequence: `ontwerp` → `akkoord-mandaat` → `ondertekend` → `verzonden` → `ontvangen-bevestiging` → `gearchiveerd`. Every transition SHALL be logged with actor, timestamp, and evidence material.

**Feature tier**: V1

#### Scenario: Invalid state transition is rejected

- **GIVEN** a beschikking with status `ondertekend`
- **WHEN** an attempt is made to jump directly to `gearchiveerd`
- **THEN** the system SHALL reject the transition with HTTP 409
- **AND** the state SHALL remain `ondertekend`
- **AND** the error message SHALL list the allowed next states

#### Scenario: Every transition is logged

- **GIVEN** a beschikking transitions from one state to another
- **WHEN** the transition is processed
- **THEN** a `StateMachineLog` entry SHALL be created with:
  - `van`, `naar`, `tijdstip`, `actor`, `actorType`
  - `trigger` (handmatig | automatisch)
  - `bewijsMateriaal` (TSP-rapport-id, berichtId, etc.)

### Requirement: Bezwaartermijn-trigger op bekendmakingsdatum (REQ-BES-006)

The system SHALL automatically start a 6-week bezwaar term (per Awb art. 6:7) on the bekendmakingsdatum, schedule a reminder 1 week before expiry, and when a bezwaarschrift is received, automatically link it to the original beschikking.

**Feature tier**: V1

#### Scenario: Bezwaar-termijn is calculated and reminder scheduled

- **GIVEN** a beschikking is delivered with `bekendmakingDatum: 2026-04-02`
- **WHEN** the state transitions to `verzonden`
- **THEN** `bezwaarTermijnEindDatum` SHALL be calculated as `2026-05-14` (6 weeks later)
- **AND** `herinneringDatum` SHALL be set to `2026-05-07` (1 week before end)
- **AND** a `BezwaarTrigger` object SHALL be created with `archiefTriggerActief: true` and `archiefDatum: 2026-05-15`

#### Scenario: Received bezwaarschrift is linked to the decision

- **GIVEN** a bezwaarschrift is received and registered against a beschikking
- **WHEN** the bezwaar-system processes the input
- **THEN** the original beschikking's `bezwaarOntvangen` flag SHALL be set to `true`
- **AND** the `bezwaarZaakId` SHALL be recorded
- **AND** the archival trigger SHALL be disabled (to prevent archival while bezwaar is pending)

### Requirement: Archiefoverdracht met TMLO/MDTO-metadata (REQ-BES-007)

After the bezwaar term expires (or bezwaar is denied/withdrawn), the beschikking SHALL be automatically consolidated to an immutable archived copy and transferred to the archief (OpenRegister) with complete TMLO or MDTO metadata.

**Feature tier**: V1

#### Scenario: Beschikking is archived after bezwaar-term expiry

- **GIVEN** a beschikking with `bezwaarTermijnEindDatum: 2026-05-14` and no bezwaar received
- **WHEN** a daily batch job runs on 2026-05-15
- **THEN** the system SHALL call OpenRegister to ingest the beschikking
- **AND** TMLO-1.2 metadata SHALL be generated based on gemeente-config
- **AND** the state SHALL transition to `gearchiveerd`
- **AND** `archief.gearchiveerdOp`, `archief.archiefId`, and `archief.vernietigingsdatum` SHALL be recorded

#### Scenario: MDTO is used if configured for the gemeente

- **GIVEN** a gemeente is configured to use MDTO instead of TMLO
- **WHEN** the archival job runs
- **THEN** the system SHALL generate MDTO-format metadata instead of TMLO
- **AND** the metadata block SHALL be passed to OpenRegister

### Requirement: Niet-wijzigbare beschikking na ondertekening (REQ-BES-008)

A beschikking with status `ondertekend` or later SHALL NOT be edited substantively; only process events (delivery, receipt confirmation, bezwaar linking) are allowed.

**Feature tier**: V1

#### Scenario: Substantive edit is rejected after ondertekend

- **GIVEN** a beschikking with status `ondertekend`
- **WHEN** an attempt is made to modify `motivering` or `beslissing.omvang`
- **THEN** the system SHALL reject the PATCH with HTTP 409
- **AND** the response SHALL include a message that a new wijzigingsbeschikking or intrekkingsbeschikking must be created

#### Scenario: Wijzigingsbeschikking references the original

- **GIVEN** a handler decides to correct an ondertekend beschikking
- **WHEN** they initiate "wijzigingsbeschikking opstellen"
- **THEN** a new Beschikking SHALL be created with `beschikkingType: wijziging`
- **AND** the new beschikking SHALL explicitly reference the original beschikking ID
- **AND** the original SHALL remain ondertekend and unmodified

### Requirement: Audit-bewijs voor juridische verificatie (REQ-BES-009)

The system SHALL provide an exportable audit-proof package containing all state transitions, mandaat references, TSP validation reports, delivery proofs, and receipt confirmations.

**Feature tier**: V1

#### Scenario: Audit-pakket is exported with all evidence

- **GIVEN** a request to export an audit-pakket for a beschikking
- **WHEN** the handler calls `GET /api/beschikkingen/{id}/audit-pakket` (download-endpoint)
- **THEN** the system SHALL generate a ZIP file containing:
  - The archived PDF (final version)
  - All `StateMachineLog` entries (JSON)
  - The `MandaatRegeling` object (state at time of akkoord)
  - The TSP validatierapport (referenced by ID)
  - Berichtenbox delivery proofs (berichtId, timestamps)
  - Any linked bezwaar-zaak ID
  - A manifest file describing the package contents
- **AND** the ZIP SHALL be cryptographically signed by Procest (PKCS#7)
- **AND** the response SHALL include `Content-Type: application/zip` with appropriate download headers

#### Scenario: eIDAS-signature is verifiable in audit-pakket

- **GIVEN** an audit-pakket for a beschikking
- **WHEN** the TSP validatierapport and the signed PDF are verified
- **THEN** the signature integrality SHALL be confirmed
- **AND** the TSP certificate chain SHALL be verifiable against the Europese Trust List at the time of signing

### Requirement: Templates versiebeheer met effectieve datum (REQ-BES-010)

Beschikking templates in Docudesk SHALL be versioned with an effective date (`ingangsdatum`). The composition of a beschikking SHALL always use the template version that was effective on its bekendmakingsdatum.

**Feature tier**: V1

#### Scenario: Correct template version is selected based on known date

- **GIVEN** template `tpl-wmo-toekenning-huishoudelijke-hulp` has:
  - version 3 with `ingangsdatum: 2025-01-01`
  - version 4 with `ingangsdatum: 2026-01-01`
- **WHEN** a beschikking is composed on 2026-04-01
- **THEN** version 4 SHALL be used (since 2026-04-01 ≥ 2026-01-01)

#### Scenario: Old beschikking re-issued uses original template version

- **GIVEN** a beschikking from 2025 is re-issued (corrected copy)
- **WHEN** the composition occurs with the original `bekendmakingDatum: 2025-06-15`
- **THEN** the system SHALL use template version 3 (valid in 2025), not version 4
- **AND** the beschikking text SHALL be consistent with the original

### Requirement: Data Model for Beschikking (REQ-BES-011)

The Procest register SHALL define the `Beschikking`, `StateMachineLog`, `BezwaarTrigger`, and `MandaatRegeling` entities with all required properties, constraints, and relations per the data model in design.md.

**Feature tier**: V1

#### Scenario: Beschikking entity is fully queryable

- **GIVEN** a Procest instance with seeded beschikkingen
- **WHEN** the system queries `GET /api/beschikkingen?huidigeStatus=ondertekend`
- **THEN** the response SHALL include all ondertekend beschikkingen with their full payloads

#### Scenario: Immutability of ondertekend beschikking is enforced at schema level

- **GIVEN** the `Beschikking` schema definition in `procest_register.json`
- **WHEN** the schema is inspected
- **THEN** it SHALL include a `readOnlyFields` array or equivalent guard that lists `motivering`, `beslissing`, `geadresseerde`, etc.
- **AND** these fields SHALL be immutable once `huidigeStatus ∈ {ondertekend, verzonden, ontvangen-bevestiging, gearchiveerd}`

## MODIFIED Requirements

(None in V1)

## Seed Data

Procest ships three example beschikkingen in different lifecycle states under `lib/Settings/seed/beschikkingen.json`:

1. **besch-2026-04832**: WMO huishoudelijke hulp (gearchiveerd) — demonstrates full lifecycle completion
2. **besch-2026-00156**: Omgevingsvergunning (ondertekend, awaiting delivery) — demonstrates post-signature state
3. **besch-2026-00489**: Subsidietoekenning (ontwerp) — demonstrates draft state with missing required fields

All seed beschikkingen reference zaaktypes that are seeded by the base-register-seed-data change.

## Standards & Sources

- **Algemene wet bestuursrecht (Awb)** — articles 3:41 (bekendmaking), 6:7 (bezwaartermijn), 1:3 (besluit), 10:3–10:12 (mandaat)
- **eIDAS-verordening (EU) 910/2014** — qualified electronic signature, TSP, European Trust List
- **Wet elektronische dienstverlening burgerzaken (Wedb)** — Berichtenbox for burgers
- **TMLO 1.2** — Toepassingsprofiel Metadatering Lokale Overheden (VHIC/VNG)
- **MDTO** — Metagegevens voor Duurzaam Toegankelijke Overheidsinformatie (Nationaal Archief)
- **Archiefwet 1995** — archival and destruction obligations
- **NEN-ISO 14641:2018** — digital archiving requirements
- **PDF/A-3 (ISO 19005-3)** — durable archive format with embedded attachments
- **ETSI EN 319 102-1** — AdES signature creation and validation procedures
- **ETSI EN 319 162** — Associated Signature Container (ASiC)
