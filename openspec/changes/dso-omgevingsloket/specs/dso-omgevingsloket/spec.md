---
status: proposed
---

# DSO Omgevingsloket Integration

**Owned by**: Procest (VTH case type for omgevingsvergunningen)

## Purpose
Provide VTH (Vergunningen, Toezicht, Handhaving) case management for DSO (Digitaal Stelsel Omgevingswet) related data within Procest. This spec defines the omgevingsvergunning as a case type in Procest, covering vergunningaanvragen, activiteiten, locaties, omgevingsdocumenten, and related entities conforming to DSO data models (STAM, IMOW). OpenRegister provides the underlying register storage for structured DSO objects; Procest provides the case lifecycle management (status transitions, deadline tracking, behandelaar assignment, beschikking generation). Where OpenConnector's `dso-omgevingsloket` spec handles *connecting to* the DSO-LV as a source, this spec defines how Procest *manages the VTH workflow* and how OpenRegister *stores and exposes* DSO data as structured register objects with DSO-compatible API output. Cross-references the `vth-module` spec for broader VTH capabilities.

**Tender demand**: 32% of analyzed government tenders require VTH (Vergunningen, Toezicht, Handhaving) capabilities aligned with the Omgevingswet/DSO. Municipalities need a register to store and query omgevingsvergunning data locally while maintaining compatibility with the national DSO-LV system. VTH-specific requirements appear in 20 of 69 procest-relevant tenders, with municipalities such as Zoetermeer (282155) and Westerkwartier (264852) specifying detailed DSO-LV integration requirements including triggerbericht ontvangst, verzoek ophalen, samenwerkfunctionaliteit, and beschikking generation.

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

## Data Model

#### Schema: Vergunningaanvraag (Permit Application)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| identificatie | string | Yes | Unique application identifier (DSO-LV verzoekId format: `nl.dso.aanvraag.{year}-{code}-{seq}`) |
| activiteiten | array (strings) | Yes | References to Activiteit objects via their `identificatie` values |
| locatie | object | Yes | Location reference with `identificatie` (ref to Locatie) and `adres` (human-readable) |
| initiatiefnemer | object | No | Applicant details: `naam` (string), `type` (enum: `particulier`, `bedrijf`) |
| bevoegdGezag | string | No | Competent authority handling the application (organization name or OIN) |
| status | string (enum) | Yes | `ingediend`, `in_behandeling`, `verleend`, `geweigerd`, `ingetrokken` |
| indieningsdatum | date | Yes | Date the application was submitted (ISO 8601 date format) |
| besluitdatum | date | No | Date the decision was made (set when status = verleend/geweigerd) |
| bijlagen | array (objects) | No | Attachments: each with `naam` (filename) and `type` (document category) |
| toelichting | string | No | Additional explanation, decision motivation, or rejection reasoning |

#### Schema: Activiteit (Activity)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| identificatie | string | Yes | Unique IMOW-style identifier (e.g., `nl.imow-gm0363.activiteit.bouwen`) |
| naam | string | Yes | Human-readable activity name (e.g., `Dakkapel plaatsen`) |
| activiteitgroep | string | No | Category group (bouwactiviteiten, sloopactiviteiten, kapactiviteiten, milieuactiviteiten, gebruiksactiviteiten, uitritactiviteiten, evenementenactiviteiten) |
| regelkwalificatie | string (enum) | Yes | `vergunningplicht`, `meldingsplicht`, `informatieplicht`, `vergunningvrij` |
| bovenliggendeActiviteit | string | No | Reference to parent activity `identificatie` for hierarchy (null for root) |
| omschrijving | string | No | Extended description of the activity |

#### Schema: Locatie (Location)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| identificatie | string | Yes | Unique IMOW-style identifier (e.g., `nl.imow-gm0363.locatie.amsterdamCentrum`) |
| naam | string | Yes | Human-readable location name or address |
| type | string (enum) | Yes | `adres`, `gebied`, `gemeente`, `waterschap`, `provincie` |
| gemeenteCode | string | No | CBS gemeentecode (4-digit string, e.g., `0363` for Amsterdam) |
| gemeenteNaam | string | No | Municipality name |
| adres | object | No | Structured address: `straat`, `huisnummer` (integer), `huisletter`, `postcode`, `woonplaats` |

#### Schema: Omgevingsdocument (Environmental Document)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| identificatie | string | Yes | IMOW-compliant identifier (e.g., `nl.imow-gm0363.omgevingsdocument.omgevingsplanAmsterdam`) |
| type | string (enum) | Yes | `omgevingsplan`, `omgevingsverordening`, `waterschapsverordening`, `AMvB`, `ministeriele_regeling` |
| status | string (enum) | No | `ontwerp`, `vastgesteld`, `ingetrokken` |
| bevoegdGezag | string | No | Competent authority (organization name or OIN) |
| titel | string | Yes | Document title |
| publicatiedatum | date | No | Date of publication (ISO 8601 date format) |

## Non-Requirements
- **Running a DSO-LV node**: OpenRegister is not a replacement for the national DSO-LV infrastructure; it stores and manages DSO-related data locally.
- **Full IMOW compliance**: The omgevingsdocument schema captures key IMOW fields but does not implement the complete IMOW information model (which includes annotaties, juridische regels, and complex OW-object hierarchies).
- **DSO-LV connectivity**: Actual connection to DSO-LV is handled by OpenConnector (see `openconnector/openspec/specs/dso-omgevingsloket/spec.md`). This spec covers data storage only.
- **Toepasbare regels engine**: Executing STTR (Standard voor Toepasbare Regels) rule sets for automated vergunningcheck is out of scope; OpenRegister stores the data, but rule execution belongs in a dedicated rules engine.
- **3D geometry / BIM integration**: Complex 3D building models and BIM data are out of scope for the base DSO register schemas.
- **Legesberekening**: Calculating permit fees (leges) from bouwkosten or activiteit types is out of scope for this spec. Legesberekening belongs in Procest or a dedicated financial module.
- **Bezwaar en beroep**: The objection and appeal process (bezwaar/beroep) workflow is handled by Procest, not by DSO register data storage.

## Dependencies
- **OpenRegister core**: Schema management, object CRUD, RBAC, multi-tenancy, audit trail (ADR-001)
- **OpenRegister mapping engine**: Twig-based property/value mapping shared with `zgw-api-mapping` spec
- **OpenConnector DSO adapter**: Inbound/outbound DSO-LV communication (separate spec, separate app)
- **Procest**: Zaak lifecycle management for vergunningaanvragen that become cases (optional)
- **Docudesk**: PDF generation for beschikkingen (optional)
- **geo-metadata-kaart spec**: Spatial queries for locatie geometry and werkingsgebied areas (when GeoJSON geometry is added to locatie schema)
- **BAG/BRK reference data**: Address and cadastral validation via `bag_register.json` mock data or OpenConnector BAG source
- **Nextcloud INotifier**: Notification delivery for DSO events

## Using Mock Register Data

The **DSO** mock register provides test data for omgevingsvergunning development and demos.

**Loading the register:**
```bash
# Load DSO register (53 records, register slug: "dso", schemas: "activiteit", "locatie", "omgevingsdocument", "vergunningaanvraag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json
```

**Test data available:**
- **Activiteiten** (25 records): Covers bouwactiviteiten (bouwen, dakkapel, aanbouw, zonnepanelen, schutting laag/hoog, kozijnen, gevel), sloopactiviteiten (slopen, asbest, overig), kapactiviteiten (kappen, boom kappen), milieuactiviteiten (milieu, bedrijfsactiviteit, lozing, opslag gevaarlijke stoffen), gebruiksactiviteiten (gebruik wijzigen, bestemmingswijziging, kamerverhuur), uitritactiviteiten (uitrit, uitrit aanleggen), evenementenactiviteiten (evenementen, organiseren, vuurwerk)
- **Locaties** (12 records): Amsterdam Centrum, Amsterdam Zuid, Rotterdam Centrum, Den Haag Centrum, Utrecht Binnenstad, Groningen Centrum, Almere Centrum, Enschede Centrum, Maastricht Centrum, heel gemeente Voorbeeldstad, Prinsengracht Amsterdam (adres), Boterdiep Groningen (adres)
- **Omgevingsdocumenten** (6 records): Omgevingsplannen (Amsterdam, Rotterdam, Voorbeeldstad, Den Haag), Omgevingsverordening Noord-Holland, Waterschapsverordening AGV
- **Vergunningaanvragen** (10 records): Various statuses (ingediend, in_behandeling, verleend, geweigerd) covering dakkapel, aanbouw, zonnepanelen, boom kappen, sloopmelding asbest, gevel wijzigen, kamerverhuur, evenement, bedrijfsactiviteit, uitrit

**Querying mock data:**
```bash
# List all activiteiten
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{dso_register_id}/{activiteit_schema_id}" -u admin:admin

# Find vergunningaanvragen by status
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{dso_register_id}/{vergunningaanvraag_schema_id}?_search=verleend" -u admin:admin

# Filter activiteiten by regelkwalificatie
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{dso_register_id}/{activiteit_schema_id}?regelkwalificatie=vergunningplicht" -u admin:admin

# Filter locaties by gemeenteCode
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{dso_register_id}/{locatie_schema_id}?gemeenteCode=0363" -u admin:admin
```

## Current Implementation Status

#### Implemented
- **Mock register template**: `lib/Settings/dso_register.json` contains 53 realistic DSO records across 4 schemas, loadable via `openregister:load-register` CLI command. This is the primary implementation artifact.

#### Partially relevant existing infrastructure
- **Schema system** (`lib/Db/Schema.php`, `lib/Service/SchemaService.php`): OpenRegister's core schema system supports defining custom schemas with property definitions, validation, and relationships. DSO schemas are registered as standard OpenRegister schemas via the register template.
- **GeoJSON support**: OpenRegister can store GeoJSON geometry in object properties. Spatial querying requires Solr or Elasticsearch with geo_shape field type (see `geo-metadata-kaart` spec).
- **Mapping engine** (`lib/Service/MappingService.php`): Twig-based mapping is available for translating between internal and external property names/values, directly applicable for DSO API output formatting.
- **Object references** (`lib/Service/ObjectService.php`): OpenRegister supports inter-object references via UUID and identification fields, which model the relationships between vergunningaanvragen, activiteiten, locaties, and omgevingsdocumenten.
- **Import/export** (`lib/Service/Configuration/ImportHandler.php`, `ExportHandler.php`): Configuration import/export distributes pre-built DSO schema templates via `dso_register.json`.
- **Audit trail** (`lib/Db/AuditTrail.php`): Existing audit trail captures object changes, supporting the status transition tracking required for vergunningaanvragen.
- **Multi-tenancy** (`lib/Db/MultiTenancyTrait.php`): OpenRegister's organization/tenant model supports multiple municipalities using the same instance with isolated data.
- **Searchable schemas**: All four DSO schemas have `searchable: true`, enabling full-text search via Solr/Elasticsearch backends.
- **Soft validation**: All DSO schemas have `hardValidation: false`, allowing flexible data entry while still enforcing enum constraints on defined fields.

#### Not implemented
- DSO-LV API output mapping definitions (Twig mapping templates for DSO-LV koppelvlak)
- Vergunningcheck data query endpoint (combining activiteiten, locaties, and regelkwalificaties)
- DSO status lifecycle validation (enforcing allowed transitions beyond enum constraint)
- Spatial query support for locatie geometry (depends on `geo-metadata-kaart` spec and index backend)
- IMOW identification format validation (soft or hard)
- GeoJSON geometry fields on locatie schema (current locatie schema uses structured address but no GeoJSON)
- Procest zaak integration for omgevingsvergunning case management
- Notification dispatch for DSO events (new vergunningaanvraag, deadline warnings)
- Samenwerkverzoek schema for multi-bevoegd-gezag coordination
- STAM import from national catalog API (beyond the static register template)

## Standards & References
- **Omgevingswet (2024)**: Dutch Environment and Planning Act, effective January 1, 2024. Replaces Wabo, Wro, Wet milieubeheer, and 26 other laws.
- **DSO-LV (Digitaal Stelsel Omgevingswet - Landelijke Voorziening)**: National digital system operated by Kadaster/RWS. Provides Omgevingsloket, vergunningcheck, regelgeving, and STAM.
- **STAM (Stelselcatalogus Activiteiten Module)**: National catalog of activiteiten under the Omgevingswet with standardized codes, regelkwalificaties, and bevoegd gezag assignments.
- **IMOW (Informatiemodel Omgevingswet)**: Information model for omgevingsdocumenten, defining structure for omgevingsplannen, -visies, and -verordeningen. Maintained by Geonovum.
- **STOP/TPOD (Standaard Officiële Publicaties / Toepassingsprofiel Omgevingsdocumenten)**: Publication standard for omgevingsdocumenten.
- **GeoJSON (RFC 7946)**: Standard for encoding geographic data, used for locatie geometrie and werkingsgebieden.
- **BAG (Basisregistratie Adressen en Gebouwen)**: National address and building registry, managed by Kadaster.
- **BRK (Basisregistratie Kadaster)**: National cadastral registry for kadastrale aanduidingen.
- **OIN (Organisatie-Identificatienummer)**: Unique identifier for Dutch government organizations, used as `bevoegdGezag` identifier.
- **CBS Gemeentecodes**: 4-digit municipality codes maintained by CBS (Centraal Bureau voor de Statistiek).
- **PKIoverheid**: Dutch government PKI for mTLS authentication with DSO-LV (relevant for OpenConnector adapter, referenced here for context).
- **STTR (Standaard voor Toepasbare Regels)**: Standard for executable rules used in the vergunningcheck (out of scope for this spec, but referenced for context).
- **GEMMA VTH-referentiecomponent**: VNG reference architecture for VTH systems, defining minimum capabilities for vergunningverlening, toezicht, and handhaving.
- **Common Ground principles**: API-first, data-at-the-source architecture for Dutch municipalities.
- **ADR-001**: OpenRegister as Universal Data Layer -- all domain data in OpenRegister schemas.
- **ADR-002**: REST API Conventions -- URL patterns, pagination, error responses.
- **ADR-006**: OpenRegister Schema Standards -- schema.org vocabulary where applicable, Dutch government fields via mapping layer.

## Specificity Assessment

#### Sufficient for implementation
- The four core schemas (vergunningaanvraag, activiteit, locatie, omgevingsdocument) are fully defined in `dso_register.json` with field types, enums, and required flags.
- The mock data template provides 53 realistic records that serve as both test data and a living schema documentation.
- The relationship between OpenRegister (data store) and OpenConnector (connection layer) is explicitly defined with scenario-based boundary clarification.
- Status lifecycle is defined with valid enum values.
- ADR compliance is cross-referenced throughout (ADR-001, ADR-002, ADR-006).

#### Missing or ambiguous
- **STAM import mechanism**: The spec requires STAM import but the current implementation is static (register template JSON). No dynamic import from the national STAM catalog API is specified.
- **Spatial query syntax**: Location queries are limited to structured fields (gemeenteCode, gemeenteNaam). Full spatial queries (bounding box, point-in-polygon) depend on the `geo-metadata-kaart` spec.
- **GeoJSON on locatie**: The current `dso_register.json` locatie schema does not include GeoJSON geometry fields. When the `geo-metadata-kaart` spec is implemented, the locatie schema should be extended with `geometrie` (GeoJSON Point/Polygon).
- **Versioning of omgevingsdocumenten**: IMOW supports multiple versions of omgevingsdocumenten (ontwerp, vastgesteld, consolidated). The versioning strategy beyond status enum is not specified.
- **Samenloop between activiteiten**: When multiple activiteiten apply to one locatie with different bevoegd gezag (gemeente + waterschap), the coordination mechanism is defined at the OpenConnector level but the data model for samenwerkverzoeken is not yet specified.
- **Besluit as separate schema**: The original spec included a Besluit schema; the current `dso_register.json` does not include it. Besluit data is currently stored inline on the vergunningaanvraag (`besluitdatum` + `toelichting`). A separate Besluit schema may be needed for complex decision structures with voorschriften and bezwaartermijn.

#### Open questions
1. Should GeoJSON geometry be added directly to the locatie schema or managed via the `geo-metadata-kaart` spec's `geo:point` / `geo:polygon` property types?
2. How should STAM reference data be kept in sync -- periodic import from DSO-LV APIs, manual upload of updated JSON templates, or both?
3. Should the Besluit (decision) be a separate schema or remain inline on the vergunningaanvraag? For municipalities that need voorschriften (conditions), bezwaartermijn, and separate beschikking documents, a separate schema may be warranted.
4. How does the DSO register relate to the product-service-catalog spec -- are omgevingsvergunningen also products in the PDC sense?
5. Should status transition rules be enforced at the schema level (e.g., cannot go from `verleend` back to `ingediend`) or left to application logic in Procest?

## Nextcloud Integration Analysis

**Status**: Mock register template implemented (`dso_register.json` with 53 records). No DSO-specific mapping definitions, vergunningcheck endpoints, or notification dispatch yet. The core OpenRegister infrastructure (schemas, objects, mapping engine, audit trail, multi-tenancy) provides the foundation.

**Nextcloud Core Interfaces**:
- `routes.php`: Register a DSO API endpoint group (e.g., `/api/dso/`) for DSO-compatible output. Alternatively, use the generic mapping route infrastructure once the `zgw-api-mapping` spec's mapping engine is operational.
- `IEventDispatcher`: Fire typed events (e.g., `ObjectCreatedEvent`, `ObjectUpdatedEvent` with DSO schema context) when a vergunningaanvraag is created or changes status, enabling OpenConnector to push updates to DSO-LV.
- `IJobList` / `TimedJob`: Schedule periodic STAM reference data sync checks and deadline warning notifications as background jobs.
- `INotifier` / `INotification`: Send notifications to behandelaars when new vergunningaanvragen arrive from DSO-LV or when deadlines approach.

**Implementation Approach**:
- The `dso_register.json` template already defines the four DSO schemas and 53 mock objects. Deploy via `openregister:load-register` CLI command or repair step during app installation.
- Use `MappingService` for bidirectional property mapping when DSO-LV API compatibility is needed. Since DSO schemas already use Dutch property names natively (per ADR-006), the mapping primarily handles structural transformations for DSO-LV koppelvlak compliance.
- Leverage OpenConnector as the external API gateway for DSO-LV communication. OpenRegister stores and validates the data; OpenConnector handles mTLS/PKIoverheid authentication and DSO-LV protocol specifics (triggerbericht, verzoek ophalen, samenwerkfunctionaliteit).
- Use `AuditTrailMapper` for recording status transitions on vergunningaanvragen, providing the immutable audit history required for government processes (Woo transparency).
- When `geo-metadata-kaart` spec is implemented, extend the locatie schema with GeoJSON geometry fields for spatial querying.

**Dependencies on Existing OpenRegister Features**:
- `SchemaService` / `RegisterService` -- schema definitions and register provisioning.
- `MappingService` -- Twig-based property/value mapping for DSO API output formatting.
- `ObjectService` -- CRUD with validation, filtering, and inter-object references.
- `AuditTrailMapper` -- status transition logging and change history.
- `ImportHandler` / `ExportHandler` -- register template distribution and loading.
- `MultiTenancyTrait` -- municipality-scoped data isolation.
- `IndexService` -- Solr/Elasticsearch integration for search performance.
- OpenConnector app -- external DSO-LV connectivity (separate app, separate spec).
