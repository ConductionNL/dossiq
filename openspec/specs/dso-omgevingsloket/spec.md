---
status: done
note: Implemented and archived 2026-06-13 (change dso-omgevingsloket). Schemas, VergunningaanvraagCreatedListener, VergunningStatusChangedEvent, DsoDeadlineJob, working-day calculator (Dutch holidays), SamenwerkverzoekService, DSOIntakeController, and the VTH dashboard + dialogs shipped. V01/V04/V06/V08 statically verified; V02/V03/V05/V07/V09/V10 deferred ([~]) as live-integration/runtime verification (and V07 also blocked on docudesk PDF/A-3).
---

# dso-omgevingsloket Specification

## Purpose
Integrates Procest with the DSO/Omgevingsloket: ingests vergunningaanvragen from OpenConnector into Procest zaken with statutory working-day deadlines, drives the omgevingsvergunning status lifecycle (dual-write + event dispatch), supports samenwerkverzoek and doorstuur flows, generates beschikkingen, and surfaces a VTH dashboard.
## Requirements
### Requirement: REQ-DSO-001 -- Register schemas for core DSO entities
OpenRegister MUST provide register schemas for the core DSO entity types, enabling structured storage of omgevingsvergunning-related data. All schemas MUST be defined as OpenRegister schemas per ADR-001 (OpenRegister as Universal Data Layer) and MUST NOT use custom database tables. Schemas SHALL be registered during installation via repair steps or the `openregister:load-register` CLI command using the `dso_register.json` template.

#### Scenario: Create a vergunningaanvraag object
- **GIVEN** the DSO register is configured with the `vergunningaanvraag` schema
- **WHEN** an operator creates a new vergunningaanvraag with:
  - `identificatie`: `nl.dso.aanvraag.2026-AMS-001`
  - `activiteiten`: `["nl.imow-gm0363.activiteit.dakkapelPlaatsen"]`
  - `locatie`: `{ "identificatie": "nl.imow-gm0363.locatie.amsterdamCentrum", "adres": "Keizersgracht 123, 1015CJ Amsterdam" }`
  - `initiatiefnemer`: `{ "naam": "J. de Vries", "type": "particulier" }`
  - `bevoegdGezag`: `Gemeente Amsterdam`
  - `status`: `ingediend`
  - `indieningsdatum`: `2026-03-15`
- **THEN** the object MUST be stored with all fields validated against the schema
- **AND** the `identificatie` MUST be unique within the register

#### Scenario: Create an activiteit object with hierarchy
- **GIVEN** the DSO register is configured with the `activiteit` schema
- **WHEN** an operator creates an activiteit with:
  - `identificatie`: `nl.imow-gm0363.activiteit.dakkapelPlaatsen`
  - `naam`: `Dakkapel plaatsen`
  - `activiteitgroep`: `bouwactiviteiten`
  - `regelkwalificatie`: `vergunningplicht`
  - `bovenliggendeActiviteit`: `nl.imow-gm0363.activiteit.bouwen`
- **THEN** the activiteit MUST be stored as a register object
- **AND** the `identificatie` MUST be unique within the register
- **AND** the `bovenliggendeActiviteit` reference MUST point to an existing activiteit object or be null for root-level activities

#### Scenario: Create a locatie object with address
- **GIVEN** the DSO register is configured with the `locatie` schema
- **WHEN** an operator creates a locatie with:
  - `identificatie`: `nl.imow-gm0363.locatie.amsterdamCentrum`
  - `naam`: `Amsterdam Centrum`
  - `type`: `gebied`
  - `gemeenteCode`: `0363`
  - `gemeenteNaam`: `Amsterdam`
  - `adres`: `{ "straat": "Keizersgracht", "huisnummer": 123, "postcode": "1015CJ", "woonplaats": "Amsterdam" }`
- **THEN** the locatie MUST be stored with CBS gemeentecode validated as a 4-digit string
- **AND** the locatie type MUST be one of: `adres`, `gebied`, `gemeente`, `waterschap`, `provincie`

#### Scenario: Create an omgevingsdocument object
- **GIVEN** the DSO register is configured with the `omgevingsdocument` schema
- **WHEN** an operator creates an omgevingsdocument with:
  - `identificatie`: `nl.imow-gm0363.omgevingsdocument.omgevingsplanAmsterdam`
  - `type`: `omgevingsplan`
  - `status`: `vastgesteld`
  - `bevoegdGezag`: `Gemeente Amsterdam`
  - `titel`: `Omgevingsplan Amsterdam`
  - `publicatiedatum`: `2024-01-01`
- **THEN** the document MUST be stored with IMOW-compliant identification format (`nl.imow-{bevoegdGezagCode}.{type}.{naam}`)
- **AND** the type MUST be one of: `omgevingsplan`, `omgevingsverordening`, `waterschapsverordening`, `AMvB`, `ministeriele_regeling`

#### Scenario: Reject invalid enum values
- **GIVEN** the `vergunningaanvraag` schema defines `status` as an enum with values `ingediend`, `in_behandeling`, `verleend`, `geweigerd`, `ingetrokken`
- **WHEN** an operator attempts to create a vergunningaanvraag with `status`: `onbekend`
- **THEN** the creation MUST fail with a 422 validation error per ADR-002 error response conventions
- **AND** the error message MUST indicate which enum values are valid

### Requirement: REQ-DSO-002 -- STAM data model alignment
OpenRegister's DSO schemas MUST align with the STAM (Stelselcatalogus Activiteiten Module) data model, enabling interoperability with the national DSO-LV. The `activiteit` schema SHALL include all STAM-required properties: `identificatie`, `naam`, `activiteitgroep`, `regelkwalificatie`, and `bovenliggendeActiviteit` for hierarchical relationships. Per ADR-006, Dutch DSO-specific field names are acceptable since there is no schema.org equivalent for STAM concepts; the mapping layer handles translation for external APIs.

#### Scenario: STAM-aligned activiteit schema validation
- **GIVEN** the STAM defines activiteiten with properties: `identificatie`, `naam`, `groep`, `regelkwalificatie`, `bevoegdGezag`
- **WHEN** the `activiteit` schema is configured in OpenRegister
- **THEN** each STAM property MUST map to an OpenRegister schema property
- **AND** the `regelkwalificatie` MUST be constrained to: `vergunningplicht`, `meldingsplicht`, `informatieplicht`, `vergunningvrij`
- **AND** the mapping between STAM and OpenRegister property names MUST be documented in the schema metadata

#### Scenario: Import STAM reference data from register template
- **GIVEN** the `dso_register.json` template contains 25 standard STAM-aligned activiteiten
- **WHEN** an admin loads the register via `openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json`
- **THEN** all activiteiten from the template MUST be imported as register objects (bouwen, dakkapel plaatsen, aanbouw plaatsen, zonnepanelen plaatsen, slopen, kappen, milieu, uitrit, evenementen, etc.)
- **AND** each imported object MUST retain its STAM-style `identificatie` (e.g., `nl.imow-gm0363.activiteit.bouwen`) for traceability
- **AND** parent-child relationships via `bovenliggendeActiviteit` MUST be preserved

#### Scenario: Custom activiteiten alongside STAM reference data
- **GIVEN** standard STAM activiteiten are imported from the register template
- **WHEN** a municipality defines a custom activiteit (e.g., `nl.imow-gm0363.activiteit.terrasvergunning`)
- **THEN** the custom activiteit MUST coexist with STAM activiteiten in the same register
- **AND** the custom activiteit MUST follow the same `identificatie` format
- **AND** querying activiteiten MUST return both STAM and custom entries

#### Scenario: Activiteitgroep hierarchy navigation
- **GIVEN** activiteiten are organized by `activiteitgroep` (bouwactiviteiten, sloopactiviteiten, kapactiviteiten, milieuactiviteiten, gebruiksactiviteiten, uitritactiviteiten, evenementenactiviteiten)
- **WHEN** a user queries activiteiten filtered by `activiteitgroep=bouwactiviteiten`
- **THEN** only bouwactiviteiten MUST be returned (bouwen, dakkapel plaatsen, aanbouw plaatsen, zonnepanelen plaatsen, schutting plaatsen, kozijnen vervangen, gevel wijzigen)
- **AND** the hierarchy via `bovenliggendeActiviteit` MUST be navigable from any child to its root

### Requirement: REQ-DSO-003 -- Omgevingsdocument schema conforming to IMOW
OpenRegister MUST provide a schema for omgevingsdocumenten (omgevingsplannen, -visies, -verordeningen) conforming to key IMOW (Informatiemodel Omgevingswet) data elements. The schema SHALL capture identification, type, status, competent authority (bevoegd gezag), and publication date. Full IMOW annotatie/juridische-regel support is out of scope (see Non-Requirements), but the schema MUST store sufficient metadata to reference and query omgevingsdocumenten within the DSO context.

#### Scenario: Store a municipal omgevingsplan
- **GIVEN** the DSO register has the `omgevingsdocument` schema
- **WHEN** an operator creates an omgevingsdocument with:
  - `identificatie`: `nl.imow-gm0363.omgevingsdocument.omgevingsplanAmsterdam`
  - `type`: `omgevingsplan`
  - `status`: `vastgesteld`
  - `bevoegdGezag`: `Gemeente Amsterdam`
  - `titel`: `Omgevingsplan Amsterdam`
  - `publicatiedatum`: `2024-01-01`
- **THEN** the document MUST be stored with IMOW-compliant identification
- **AND** the `identificatie` MUST follow the pattern `nl.imow-{bevoegdGezagCode}.omgevingsdocument.{naam}`

#### Scenario: Store a provincial omgevingsverordening
- **GIVEN** the DSO register has the `omgevingsdocument` schema
- **WHEN** an operator creates an omgevingsdocument with:
  - `identificatie`: `nl.imow-pv27.omgevingsdocument.omgevingsverordeningNH`
  - `type`: `omgevingsverordening`
  - `bevoegdGezag`: `Provincie Noord-Holland`
- **THEN** the document MUST be stored with the provincial bevoegd gezag code (`pv27` for Noord-Holland)
- **AND** the type MUST correctly reflect `omgevingsverordening` as opposed to municipal `omgevingsplan`

#### Scenario: Store a waterschapsverordening
- **GIVEN** the DSO register has the `omgevingsdocument` schema
- **WHEN** an operator creates an omgevingsdocument with:
  - `identificatie`: `nl.imow-ws0155.omgevingsdocument.waterschapsverordeningAGV`
  - `type`: `waterschapsverordening`
  - `bevoegdGezag`: `Waterschap Amstel, Gooi en Vecht`
- **THEN** the document MUST be stored with the waterschap bevoegd gezag code (`ws0155`)
- **AND** querying by `type=waterschapsverordening` MUST return only waterschap documents

#### Scenario: Track omgevingsdocument status lifecycle
- **GIVEN** an omgevingsdocument exists with `status`: `ontwerp`
- **WHEN** the bevoegd gezag updates the status to `vastgesteld`
- **THEN** the status change MUST be recorded in the object's audit trail (per ADR-001, all domain objects have audit trails via OpenRegister)
- **AND** the valid status values MUST be: `ontwerp`, `vastgesteld`, `ingetrokken`

#### Scenario: Query omgevingsdocumenten by bevoegd gezag
- **GIVEN** multiple omgevingsdocumenten exist for different municipalities, provinces, and waterschappen
- **WHEN** a user queries with `_search=Amsterdam` or filters by `bevoegdGezag=Gemeente Amsterdam`
- **THEN** only the Amsterdam-specific omgevingsdocumenten MUST be returned

### Requirement: REQ-DSO-004 -- DSO API output mapping via mapping engine
OpenRegister MUST support mapping internal objects to DSO-compatible API output formats, using the same Twig-based mapping engine defined in the `zgw-api-mapping` spec. The mapping engine (per `zgw-api-mapping` REQ) resides in OpenRegister as a core service and SHALL support bidirectional property mapping between English-internal names and Dutch DSO API names. For DSO schemas that already use Dutch property names natively (per ADR-006 exception for Dutch government standards), the mapping layer SHALL handle any additional transformations needed for DSO-LV API compliance.

#### Scenario: Map vergunningaanvraag to DSO-LV verzoek format
- **GIVEN** a vergunningaanvraag object in OpenRegister with properties as stored (Dutch names per the schema)
- **WHEN** the outbound DSO-LV mapping is applied for transmission to DSO-LV
- **THEN** the API response MUST conform to the DSO-LV verzoek koppelvlak specification
- **AND** the `identificatie` field MUST map to the DSO-LV `verzoekId`
- **AND** date fields MUST be formatted as ISO 8601 strings

#### Scenario: Inbound mapping from DSO-LV verzoek format
- **GIVEN** OpenConnector receives a verzoek from DSO-LV via the triggerbericht/verzoek ophaal flow (VTH010-VTH012 per Zoetermeer tender)
- **WHEN** the inbound mapping is applied
- **THEN** the object MUST be stored in OpenRegister's `vergunningaanvraag` schema with field names matching the schema definition
- **AND** the original DSO-LV `verzoekId` MUST be preserved in the `identificatie` field for traceability
- **AND** bijlagen referenced in the verzoek MUST be stored or linked via OpenRegister's file management

#### Scenario: Map activiteit to STAM catalog format
- **GIVEN** an activiteit object in OpenRegister
- **WHEN** the STAM output mapping is applied
- **THEN** the response MUST include STAM-required fields: `identificatie`, `naam`, `activiteitgroep`, `regelkwalificatie`
- **AND** the `bovenliggendeActiviteit` reference MUST be resolvable to a STAM-compatible activiteit identifier

#### Scenario: Mapping preserves all fields on round-trip
- **GIVEN** a vergunningaanvraag is received from DSO-LV and stored via inbound mapping
- **WHEN** the same object is exported via outbound mapping
- **THEN** no data MUST be lost in the round-trip
- **AND** the DSO-LV `verzoekId` and all bijlagen references MUST be identical to the original

### Requirement: REQ-DSO-005 -- Vergunningcheck data support
OpenRegister MUST store the data needed to support DSO vergunningcheck (permit checker) functionality: which activiteiten require a vergunning, melding, or informatieplicht at a given locatie. The `regelkwalificatie` enum on each activiteit (vergunningplicht, meldingsplicht, informatieplicht, vergunningvrij) SHALL be the primary mechanism for determining permit requirements. Note that executing STTR rule sets is out of scope (see Non-Requirements); OpenRegister stores the reference data that feeds into vergunningcheck queries.

#### Scenario: Query activiteit regelkwalificatie for a location
- **GIVEN** activiteiten with regelkwalificaties are stored:
  - `dakkapelPlaatsen` with `regelkwalificatie`: `vergunningplicht`
  - `sloopmeldingAsbest` with `regelkwalificatie`: `meldingsplicht`
  - `zonnepanelenPlaatsen` with `regelkwalificatie`: `vergunningvrij`
  - `evenementOrganiseren` with `regelkwalificatie`: `informatieplicht`
- **WHEN** a client queries all activiteiten
- **THEN** the response MUST list all activiteiten with their regelkwalificatie
- **AND** the response MUST distinguish between `vergunningplicht`, `meldingsplicht`, `informatieplicht`, and `vergunningvrij`

#### Scenario: Filter activiteiten by regelkwalificatie
- **GIVEN** 25 activiteiten are stored with mixed regelkwalificaties
- **WHEN** a client queries with filter `regelkwalificatie=vergunningplicht`
- **THEN** only activiteiten requiring a vergunning MUST be returned (bouwen, dakkapel plaatsen, aanbouw plaatsen, schutting >2m, gevel wijzigen, kappen, boom kappen, opslag gevaarlijke stoffen, gebruik wijzigen, bestemmingswijziging, kamerverhuur, uitrit, uitrit aanleggen, evenement met vuurwerk)
- **AND** the count MUST match the number of `vergunningplicht` activiteiten in the register

#### Scenario: Filter activiteiten by activiteitgroep and regelkwalificatie combined
- **GIVEN** activiteiten exist in multiple activiteitgroepen with varying regelkwalificaties
- **WHEN** a client queries with filters `activiteitgroep=bouwactiviteiten&regelkwalificatie=vergunningvrij`
- **THEN** only vergunningvrije bouwactiviteiten MUST be returned (e.g., zonnepanelen plaatsen, schutting <2m, kozijnen vervangen)

#### Scenario: Locatie-specific rules via omgevingsdocument linkage
- **GIVEN** activiteit `dakkapelPlaatsen` has default regelkwalificatie `vergunningplicht`
- **AND** the omgevingsplan for Amsterdam references additional indieningsvereisten for beschermd stadsgezicht areas
- **WHEN** a vergunningaanvraag for a locatie in Amsterdam Centrum is queried
- **THEN** the linked omgevingsdocument (`Omgevingsplan Amsterdam`) MUST be retrievable from the locatie's `gemeenteCode`
- **AND** the relationship between locatie, omgevingsdocument, and applicable activiteiten MUST be navigable via queries

### Requirement: REQ-DSO-006 -- Boundary between OpenRegister and OpenConnector
OpenRegister serves as the data store for DSO entities; OpenConnector serves as the connection layer to DSO-LV. The boundary MUST be clearly defined: OpenRegister owns schema validation, storage, querying, and audit; OpenConnector owns protocol handling, mTLS/PKIoverheid authentication, triggerbericht reception, verzoek ophalen, and samenwerkfunctionaliteit coordination with DSO-LV.

#### Scenario: OpenConnector receives verzoek from DSO-LV and stores in OpenRegister
- **GIVEN** OpenConnector's DSO adapter receives a triggerbericht from DSO-LV (VTH010 per Zoetermeer tender)
- **WHEN** the adapter retrieves the verzoek (VTH011) and its bijlagen (VTH012)
- **THEN** the adapter MUST create an object in OpenRegister's `vergunningaanvraag` schema via the standard REST API (`POST /index.php/apps/openregister/api/objects/{register}/{schema}`)
- **AND** OpenRegister MUST validate the object against the schema before storing
- **AND** the adapter MUST NOT use direct database access (per ADR-001)

#### Scenario: OpenRegister provides data for OpenConnector to push to DSO-LV
- **GIVEN** a vergunningaanvraag in OpenRegister has its status updated to `verleend`
- **WHEN** OpenConnector needs to push the status update to DSO-LV
- **THEN** OpenConnector reads the current state from OpenRegister via `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}`
- **AND** applies the outbound DSO mapping
- **AND** pushes to DSO-LV via its STAM koppelvlak adapter

#### Scenario: Local data management without DSO-LV connection
- **GIVEN** a municipality wants to manage omgevingsvergunningen without a live DSO-LV connection
- **WHEN** they use the DSO register schemas in OpenRegister
- **THEN** all CRUD operations MUST work independently of OpenConnector/DSO-LV connectivity
- **AND** data MUST remain DSO-compatible for future synchronization
- **AND** the `identificatie` format MUST follow IMOW conventions so data can be submitted to DSO-LV later

#### Scenario: DSO-LV samenwerkfunctionaliteit via OpenConnector
- **GIVEN** a vergunningaanvraag involves multiple bevoegd gezag (e.g., gemeente + waterschap)
- **WHEN** the gemeente needs to coordinate with the waterschap via DSO-LV samenwerkfunctionaliteit (VTH008-VTH009 per Zoetermeer tender)
- **THEN** OpenConnector MUST handle the samenwerking protocol with DSO-LV
- **AND** OpenRegister MUST store the samenwerkverzoek status and responses as related objects in the register
- **AND** the vergunningaanvraag MUST reference the samenwerkverzoeken for audit trail purposes

#### Scenario: Forwarding verzoek to another bevoegd gezag
- **GIVEN** a vergunningaanvraag is received but the municipality is not the correct bevoegd gezag
- **WHEN** the behandelaar decides to forward the verzoek (VTH019 per Zoetermeer tender)
- **THEN** OpenConnector MUST handle the DSO-LV doorstuur protocol
- **AND** OpenRegister MUST update the vergunningaanvraag status to reflect the forwarding
- **AND** the audit trail MUST record who forwarded, when, and to which bevoegd gezag

### Requirement: REQ-DSO-007 -- Demo and mock data via register template
OpenRegister MUST provide demo/mock data for DSO entities via the `dso_register.json` register template to support development, testing, and tender demonstrations. The mock data SHALL include realistic Dutch addresses, IMOW-compliant identifiers, and a representative mix of activiteiten, locaties, omgevingsdocumenten, and vergunningaanvragen.

#### Scenario: Seed DSO demo data from register template
- **GIVEN** a fresh OpenRegister installation
- **WHEN** the admin loads the DSO register via `docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json`
- **THEN** the register MUST be populated with:
  - At least 25 activiteiten covering bouwactiviteiten (8), sloopactiviteiten (3), kapactiviteiten (2), milieuactiviteiten (4), gebruiksactiviteiten (3), uitritactiviteiten (2), evenementenactiviteiten (3)
  - At least 12 locaties across multiple municipalities (Amsterdam, Rotterdam, Den Haag, Utrecht, Groningen, Almere, Enschede, Maastricht, Voorbeeldstad) with both `gebied` and `adres` types
  - At least 6 omgevingsdocumenten (omgevingsplannen, omgevingsverordening, waterschapsverordening)
  - At least 10 vergunningaanvragen in various statuses (ingediend, in_behandeling, verleend, geweigerd)
- **AND** the demo data MUST use plausible Dutch addresses and valid CBS gemeentecodes

#### Scenario: Demo data covers all regelkwalificaties
- **GIVEN** demo data is seeded
- **WHEN** a developer queries activiteiten grouped by regelkwalificatie
- **THEN** the results MUST include examples of all four types:
  - `vergunningplicht`: bouwen, dakkapel plaatsen, kappen, etc.
  - `meldingsplicht`: slopen, sloopmelding asbest, bedrijfsactiviteit starten, lozing op riolering
  - `informatieplicht`: evenementen, evenement organiseren
  - `vergunningvrij`: zonnepanelen plaatsen, schutting <2m, kozijnen vervangen

#### Scenario: Demo vergunningaanvragen cover full lifecycle
- **GIVEN** demo data is seeded
- **WHEN** a developer queries vergunningaanvragen
- **THEN** the results MUST include applications demonstrating:
  - Granted permits (`verleend`) with `besluitdatum` and positive `toelichting`
  - Refused permits (`geweigerd`) with rejection reasoning in `toelichting`
  - Pending applications (`in_behandeling`) without `besluitdatum`
  - Newly submitted applications (`ingediend`)
- **AND** applications MUST reference both `particulier` and `bedrijf` initiatiefnemers

#### Scenario: Demo data references are internally consistent
- **GIVEN** demo data is seeded
- **WHEN** a vergunningaanvraag references an activiteit via `activiteiten` array
- **THEN** the referenced activiteit `identificatie` MUST match an existing activiteit object in the register
- **AND** the referenced locatie `identificatie` MUST match an existing locatie object

### Requirement: REQ-DSO-008 -- DSO status lifecycle for vergunningaanvragen
Vergunningaanvragen in OpenRegister MUST support the DSO status lifecycle. Status values SHALL be constrained to the enum defined in the schema: `ingediend`, `in_behandeling`, `verleend`, `geweigerd`, `ingetrokken`. All status transitions MUST be recorded in the audit trail, providing the immutable history required for government processes and potential Wob/Woo (transparency) requests.

#### Scenario: Valid status transition from ingediend to in_behandeling
- **GIVEN** a vergunningaanvraag with status `ingediend`
- **WHEN** the behandelaar updates the status to `in_behandeling`
- **THEN** the status transition MUST be recorded in the object's audit trail
- **AND** the audit trail entry MUST include the user who made the change, the timestamp, and the old and new status values

#### Scenario: Valid status transition to verleend with besluitdatum
- **GIVEN** a vergunningaanvraag in status `in_behandeling`
- **WHEN** the behandelaar updates the status to `verleend` and sets:
  - `besluitdatum`: `2026-05-01`
  - `toelichting`: `Vergunning verleend. De aanvraag voldoet aan alle criteria van het omgevingsplan.`
- **THEN** the vergunningaanvraag status MUST change to `verleend`
- **AND** the `besluitdatum` MUST be set
- **AND** the `toelichting` MUST contain the decision motivation

#### Scenario: Status transition to geweigerd with rejection reasoning
- **GIVEN** a vergunningaanvraag in status `in_behandeling`
- **WHEN** the behandelaar updates the status to `geweigerd` with:
  - `besluitdatum`: `2026-05-01`
  - `toelichting`: `Vergunning geweigerd wegens strijd met het omgevingsplan.`
- **THEN** the status MUST change to `geweigerd`
- **AND** the `toelichting` MUST document the rejection reasoning for transparency (Woo)

#### Scenario: Ingetrokken by initiatiefnemer
- **GIVEN** a vergunningaanvraag in status `ingediend` or `in_behandeling`
- **WHEN** the initiatiefnemer requests withdrawal and the behandelaar sets status to `ingetrokken`
- **THEN** the status MUST change to `ingetrokken`
- **AND** the audit trail MUST record the withdrawal

#### Scenario: Audit trail provides complete status history
- **GIVEN** a vergunningaanvraag that has gone through transitions: `ingediend` -> `in_behandeling` -> `verleend`
- **WHEN** the audit trail is queried for this object
- **THEN** all status transitions MUST be listed chronologically with timestamps and users
- **AND** the audit trail MUST be immutable (entries cannot be deleted or modified)

### Requirement: REQ-DSO-009 -- Document handling for vergunningaanvragen
OpenRegister MUST support attaching documents (bijlagen) to vergunningaanvragen, covering the document types required by VTH processes: bouwtekeningen, constructieberekeningen, situatietekeningen, asbestinventarisatierapporten, veiligheidsplannen, and beschikkingen. Document storage SHALL use OpenRegister's file management capabilities and Nextcloud's underlying file system.

#### Scenario: Attach bijlagen to a vergunningaanvraag
- **GIVEN** a vergunningaanvraag exists in the register
- **WHEN** the behandelaar adds bijlagen:
  - `{ "naam": "bouwtekening-dakkapel.pdf", "type": "bouwtekening" }`
  - `{ "naam": "foto-huidige-situatie.jpg", "type": "foto" }`
- **THEN** the bijlagen array MUST be updated on the vergunningaanvraag object
- **AND** the actual files MUST be stored in the Nextcloud file system under the register's folder (`Open Registers/DSO/`)

#### Scenario: Retrieve bijlagen from DSO-LV verzoek
- **GIVEN** OpenConnector receives a verzoek from DSO-LV with bijlagen references (VTH012 per Zoetermeer tender)
- **WHEN** the bijlagen are downloaded from DSO-LV
- **THEN** each bijlage MUST be stored in OpenRegister's file system
- **AND** the bijlage metadata (naam, type) MUST be added to the vergunningaanvraag's `bijlagen` array
- **AND** the original DSO-LV document identifiers MUST be preserved

#### Scenario: Generate beschikking document
- **GIVEN** a vergunningaanvraag status is updated to `verleend` or `geweigerd`
- **WHEN** the behandelaar generates a beschikking document (via Docudesk integration)
- **THEN** the generated PDF MUST be attached as a bijlage with `type`: `beschikking`
- **AND** the beschikking MUST be linked to the vergunningaanvraag object

#### Scenario: Document type validation
- **GIVEN** the bijlage schema defines `type` values including: `bouwtekening`, `constructieberekening`, `situatietekening`, `rapport`, `foto`, `plan`, `veiligheidsplan`, `specificatie`, `omschrijving`, `werkplan`, `beschikking`
- **WHEN** a bijlage is added to a vergunningaanvraag
- **THEN** the `type` value SHOULD be from the known list but MUST NOT reject unknown types (municipalities may have custom document types)

### Requirement: REQ-DSO-010 -- Location-based queries for DSO data
OpenRegister MUST support querying DSO entities by location criteria, leveraging the `locatie` schema's `gemeenteCode`, `gemeenteNaam`, and `type` fields. Full spatial querying (bounding box, radius) is defined in the `geo-metadata-kaart` spec; this requirement covers the structured location-based filtering specific to DSO data patterns.

#### Scenario: Query vergunningaanvragen by municipality
- **GIVEN** vergunningaanvragen exist for multiple municipalities (Amsterdam 0363, Rotterdam 0599, Groningen 0014)
- **WHEN** a user queries vergunningaanvragen with a search for `Amsterdam`
- **THEN** only vergunningaanvragen with locatie references pointing to Amsterdam MUST be returned
- **AND** the query MUST work via OpenRegister's standard `_search` parameter

#### Scenario: Query locaties by gemeenteCode
- **GIVEN** locaties exist with various gemeenteCodes
- **WHEN** a user queries `GET /api/objects/{register}/{schema}?gemeenteCode=0363`
- **THEN** only locaties in Amsterdam (gemeenteCode 0363) MUST be returned
- **AND** the query MUST follow ADR-002 pagination conventions (default 30 items, `_page` and `_limit` support)

#### Scenario: Query locaties by type
- **GIVEN** locaties exist with types `adres`, `gebied`, `gemeente`
- **WHEN** a user filters by `type=adres`
- **THEN** only address-type locaties MUST be returned (e.g., `Prinsengracht, Amsterdam` and `Boterdiep, Groningen`)
- **AND** gebieden and gemeente-level locaties MUST be excluded

#### Scenario: Cross-entity location query
- **GIVEN** a vergunningaanvraag references a locatie via `locatie.identificatie`
- **WHEN** a user needs all vergunningaanvragen for a specific locatie
- **THEN** the query MUST be possible via the locatie reference field
- **AND** the response MUST include the linked locatie details (per OpenRegister's object reference expansion)

### Requirement: REQ-DSO-011 -- Multi-tenancy and bevoegd gezag isolation
OpenRegister MUST support multiple municipalities (bevoegd gezag) using the same DSO register instance with proper data isolation per ADR-001's multi-tenancy capability. Each municipality SHALL have its own register or tenant scope, ensuring that vergunningaanvragen, activiteiten, and omgevingsdocumenten from one municipality are not visible to another unless explicitly shared.

#### Scenario: Municipality-scoped data access
- **GIVEN** two municipalities (Amsterdam and Rotterdam) each have their own DSO register data
- **WHEN** a behandelaar from Amsterdam queries vergunningaanvragen
- **THEN** only Amsterdam's vergunningaanvragen MUST be returned
- **AND** Rotterdam's data MUST NOT be visible

#### Scenario: Shared STAM reference data across tenants
- **GIVEN** STAM activiteiten are national reference data used by all municipalities
- **WHEN** multiple municipalities use the same OpenRegister instance
- **THEN** STAM activiteiten SHOULD be shareable across tenants (read-only reference data)
- **AND** each municipality MAY add custom activiteiten visible only within their tenant scope

#### Scenario: Provincial omgevingsverordening visible to all municipalities in province
- **GIVEN** a provincial omgevingsverordening (e.g., Omgevingsverordening Noord-Holland) applies to all municipalities in that province
- **WHEN** a behandelaar in Amsterdam queries relevant omgevingsdocumenten
- **THEN** both the municipal omgevingsplan and the provincial omgevingsverordening MUST be returned
- **AND** the waterschapsverordening for the relevant waterschap SHOULD also be included

### Requirement: REQ-DSO-012 -- Error handling and validation
OpenRegister MUST validate all DSO entity data against schema constraints and provide clear error messages per ADR-002 error response conventions. Validation SHALL cover required fields, enum constraints, identification format, and referential integrity between DSO entities.

#### Scenario: Missing required fields on vergunningaanvraag
- **GIVEN** the `vergunningaanvraag` schema requires `identificatie`, `activiteiten`, `locatie`, `status`, `indieningsdatum`
- **WHEN** an operator attempts to create a vergunningaanvraag without `activiteiten`
- **THEN** the creation MUST fail with HTTP 422
- **AND** the error response MUST include a `message` indicating which required field is missing

#### Scenario: Invalid IMOW identification format
- **GIVEN** IMOW identifiers follow the pattern `nl.imow-{code}.{type}.{naam}`
- **WHEN** an operator creates an omgevingsdocument with `identificatie`: `invalid-format`
- **THEN** the system SHOULD warn about non-standard identification format
- **AND** the save SHOULD still succeed (soft validation) since strict IMOW format enforcement may block legitimate edge cases

#### Scenario: Referential integrity warning for activiteit references
- **GIVEN** a vergunningaanvraag references activiteiten via identification strings in the `activiteiten` array
- **WHEN** a referenced activiteit identification does not match any existing activiteit in the register
- **THEN** the system SHOULD log a warning about the unresolvable reference
- **AND** the save MUST NOT be blocked (the activiteit may be loaded later or exist in an external system)

#### Scenario: Concurrent update conflict
- **GIVEN** two behandelaars are editing the same vergunningaanvraag simultaneously
- **WHEN** both attempt to update the status
- **THEN** the second update MUST either succeed with a merge or fail with HTTP 409 Conflict
- **AND** the audit trail MUST accurately reflect which update was applied

#### Scenario: Schema validation with hardValidation disabled
- **GIVEN** DSO schemas have `hardValidation: false` (as configured in `dso_register.json`)
- **WHEN** an object is created with properties not defined in the schema
- **THEN** the extra properties MUST be stored (soft validation mode)
- **AND** the defined enum constraints SHOULD still be checked and warnings logged

### Requirement: REQ-DSO-013 -- Caching and performance for DSO queries
OpenRegister SHOULD implement caching strategies for DSO reference data (activiteiten, omgevingsdocumenten) that changes infrequently but is queried frequently. Vergunningaanvragen, which change often, MUST NOT be served from stale cache. The caching strategy SHALL leverage OpenRegister's existing index backends (Solr, Elasticsearch) for search performance.

#### Scenario: Cache activiteiten reference data
- **GIVEN** 25 activiteiten are loaded as reference data
- **WHEN** multiple clients query the activiteiten list within a short period
- **THEN** the system SHOULD serve subsequent requests from cache (Solr/Elasticsearch index or APCu)
- **AND** the cache MUST be invalidated when an activiteit is created, updated, or deleted

#### Scenario: Vergunningaanvraag queries always return current data
- **GIVEN** a vergunningaanvraag status was just updated from `in_behandeling` to `verleend`
- **WHEN** a client queries the vergunningaanvraag immediately after the update
- **THEN** the response MUST reflect the new status `verleend`
- **AND** there MUST NOT be a cache delay causing stale `in_behandeling` status to be returned

#### Scenario: Search performance with index backend
- **GIVEN** the DSO register contains 1000+ vergunningaanvragen across multiple municipalities
- **WHEN** a client performs a filtered search (e.g., `status=in_behandeling&_search=Amsterdam`)
- **THEN** the query SHOULD complete within 3 seconds (per tender SLA requirements)
- **AND** the system SHOULD leverage Solr or Elasticsearch if configured for full-text search and faceted filtering

### Requirement: REQ-DSO-014 -- Integration with Procest for zaakafhandeling
OpenRegister's DSO vergunningaanvraag objects SHALL be linkable to Procest zaak objects for full case lifecycle management. The vergunningaanvraag captures the DSO-specific data (activiteiten, locatie, initiatiefnemer); the zaak captures the case management workflow (deadlines, behandelaars, milestones). This integration is optional -- municipalities MAY use DSO data without Procest.

#### Scenario: Link vergunningaanvraag to a Procest zaak
- **GIVEN** a vergunningaanvraag is created in the DSO register
- **WHEN** the vergunningaanvraag is taken into treatment
- **THEN** a Procest zaak SHOULD be created automatically (via n8n workflow or OpenConnector event)
- **AND** the vergunningaanvraag SHOULD store a reference to the zaak for cross-navigation

#### Scenario: Procest zaak references DSO data
- **GIVEN** a Procest zaak exists for an omgevingsvergunning case
- **WHEN** a behandelaar views the zaak in Procest
- **THEN** the DSO vergunningaanvraag data (activiteiten, locatie, initiatiefnemer) MUST be retrievable from OpenRegister via the stored reference
- **AND** the activiteit regelkwalificatie MUST be visible to inform the behandelaar about permit type

#### Scenario: Status synchronization between DSO and Procest
- **GIVEN** a vergunningaanvraag in OpenRegister is linked to a Procest zaak
- **WHEN** the zaak status changes in Procest (e.g., case closed with result `verleend`)
- **THEN** the corresponding vergunningaanvraag status in OpenRegister SHOULD be updated to match
- **AND** both audit trails (Procest and OpenRegister) MUST record the synchronized status change

### Requirement: REQ-DSO-015 -- Notification support for DSO events
OpenRegister MUST fire typed events when DSO-relevant state changes occur, enabling notifications to behandelaars and integration with OpenConnector for DSO-LV synchronization. Notifications SHALL use Nextcloud's `INotifier` / `INotification` framework.

#### Scenario: Notification on new vergunningaanvraag from DSO-LV
- **GIVEN** OpenConnector receives a new verzoek from DSO-LV and stores it in OpenRegister
- **WHEN** the vergunningaanvraag object is created with status `ingediend`
- **THEN** a notification MUST be sent to the assigned behandelaar or treatment team
- **AND** the notification MUST include the aanvraag identificatie, activiteiten summary, and locatie

#### Scenario: Notification on approaching deadline
- **GIVEN** a vergunningaanvraag has been in status `in_behandeling` for more than 6 weeks (approaching the 8-week reguliere procedure deadline per Omgevingswet)
- **WHEN** a scheduled background job checks for approaching deadlines
- **THEN** a warning notification MUST be sent to the behandelaar
- **AND** the notification MUST include the remaining days and the vergunningaanvraag details

#### Scenario: Event dispatch for OpenConnector synchronization
- **GIVEN** a vergunningaanvraag status changes in OpenRegister
- **WHEN** the update is saved
- **THEN** OpenRegister MUST dispatch a typed event (e.g., `ObjectUpdatedEvent` with DSO schema context)
- **AND** OpenConnector MAY listen for this event to trigger DSO-LV status synchronization

