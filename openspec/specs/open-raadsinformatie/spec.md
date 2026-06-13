# open-raadsinformatie Specification

## Purpose
TBD - created by archiving change open-raadsinformatie. Update Purpose after archive.
## Requirements
### Requirement: ORI register MUST be provisionable with all entity schemas

The system MUST provide a pre-configured "Open Raadsinformatie" register containing all ORI entity schemas, deployable via a repair step, CLI command, or admin action. The register template MUST follow the OpenAPI 3.0.0 + `x-openregister` extension pattern used by other mock registers (BRP, KVK, BAG, DSO) and MUST be loadable via the `ConfigurationService -> ImportHandler` pipeline.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-001 | Provide a register template file `lib/Settings/ori_register.json` with all ORI schemas in OpenAPI 3.0.0 + `x-openregister` format | MUST | Done |
| REQ-ORI-002 | Register MUST be deployable via `occ openregister:load-register` CLI command or admin panel import | MUST | Done |
| REQ-ORI-003 | Each schema MUST include JSON Schema validation rules matching ORI field definitions with proper types, enums, maxLength, format, and required constraints | MUST | Done |
| REQ-ORI-004 | Register MUST expose a public OAS 3.1.0 API via the existing `OasService` generation mechanism | MUST | Planned |
| REQ-ORI-005 | Register slug MUST be `ori` for stable cross-environment references from connector configurations | MUST | Done |
| REQ-ORI-006 | All schemas MUST have `authorization.read: ["public"]` to enable unauthenticated citizen access | MUST | Done |
| REQ-ORI-007 | All schemas MUST have `searchable: true` to enable full-text search across council information | MUST | Done |

#### Scenario: Provision the ORI register via CLI
- **GIVEN** the file `lib/Settings/ori_register.json` exists with valid OpenAPI 3.0.0 + `x-openregister` format
- **WHEN** an administrator runs `occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/ori_register.json`
- **THEN** a register MUST be created with slug `ori` and title "ORI (Open Raadsinformatie)"
- **AND** the register MUST contain schemas for: vergadering, agendapunt, raadsdocument, stemming, raadslid, fractie
- **AND** each schema MUST have properly typed properties with validation rules (enums, maxLength, format constraints)
- **AND** all schemas MUST have `authorization.read: ["public"]` for citizen access

#### Scenario: Generate OAS for ORI register
- **GIVEN** the ORI register is provisioned with all schemas
- **WHEN** `GET /api/registers/{id}/oas` is called
- **THEN** the response MUST contain endpoints for all ORI entity types
- **AND** the OAS MUST include proper schema definitions with all property types, enums, and relationships
- **AND** the OAS MUST pass `redocly lint` with zero errors (per `oas-validation` spec)

#### Scenario: Re-import does not duplicate register
- **GIVEN** the ORI register already exists with slug `ori`
- **WHEN** the admin runs `occ openregister:load-register` again with the same file
- **THEN** the existing register MUST be updated, not duplicated
- **AND** existing objects MUST be preserved via the `@self` slug-based upsert mechanism

#### Scenario: Register file follows mock-registers pattern
- **GIVEN** the ORI register file at `lib/Settings/ori_register.json`
- **WHEN** the file structure is inspected
- **THEN** it MUST follow the same pattern as `brp_register.json`, `kvk_register.json`, `bag_register.json`, and `dso_register.json`
- **AND** it MUST contain `components.registers.ori`, `components.schemas.*`, and `components.objects[]` sections
- **AND** each object MUST use the `@self` envelope format with `register`, `schema`, and `slug` fields

---

### Requirement: Vergadering (Meeting) schema

The system MUST store council meetings with all ORI-standard fields. Vergaderingen are the primary organizational unit of council information: every agendapunt, document, motie, amendement, and stemming is ultimately linked to a vergadering. The schema MUST support both raadsvergaderingen (full council) and commissievergaderingen (committee sessions), with proper status lifecycle tracking.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-010 | Store vergaderingen with: naam, startDatum, eindDatum, locatie, type, status, organisatie, commissie (optional) | MUST | Done |
| REQ-ORI-011 | Vergadering types: raadsvergadering, commissievergadering, informatiebijeenkomst, hoorzitting | MUST | Done |
| REQ-ORI-012 | Vergadering status: gepland, bevestigd, afgelast | MUST | Done |
| REQ-ORI-013 | Link vergadering to agendapunten via the agendapunt.vergadering reference field | MUST | Done |
| REQ-ORI-014 | Store video/livestream URL for vergadering via a `videoUrl` property | SHOULD | Planned |
| REQ-ORI-015 | The `type` and `status` properties MUST be facetable to support filtering in search UIs | MUST | Done |
| REQ-ORI-016 | The `startDatum` property MUST use ISO 8601 `date-time` format for timezone-aware date range queries | MUST | Done |

#### Scenario: Create a raadsvergadering
- **GIVEN** the ORI register is active with slug `ori`
- **WHEN** a vergadering is created with:
  - `naam`: `Raadsvergadering 15 maart 2026`
  - `type`: `raadsvergadering`
  - `startDatum`: `2026-03-15T19:00:00+01:00`
  - `eindDatum`: `2026-03-15T23:00:00+01:00`
  - `locatie`: `Raadzaal, Gemeentehuis Voorbeeldstad`
  - `status`: `gepland`
  - `organisatie`: `Gemeente Voorbeeldstad`
- **THEN** the vergadering MUST be stored as an OpenRegister object with schema `vergadering`
- **AND** it MUST be retrievable via the public API without authentication
- **AND** the `type` and `status` values MUST be validated against their enum constraints

#### Scenario: Create a commissievergadering linked to a committee
- **GIVEN** the ORI register contains commissie "Commissie Mens & Samenleving"
- **WHEN** a vergadering is created with `type`: `commissievergadering` and `commissie`: `Commissie Mens & Samenleving`
- **THEN** the vergadering MUST store the commissie reference
- **AND** filtering by `commissie` MUST return only that committee's meetings

#### Scenario: List vergaderingen by date range
- **GIVEN** 10 vergaderingen exist between September 2025 and January 2026
- **WHEN** `GET /api/objects/{register}/{schema}?startDatum[gte]=2025-10-01&startDatum[lte]=2025-10-31` is called
- **THEN** only vergaderingen in October 2025 MUST be returned (raadsvergadering-2025-10-07, commissie-ruimte-economie-2025-10-09, raadsvergadering-2025-10-21)
- **AND** results MUST be ordered by startDatum ascending by default

#### Scenario: Filter vergaderingen by type
- **GIVEN** vergaderingen of types raadsvergadering (7) and commissievergadering (3) exist in the mock data
- **WHEN** `GET /api/objects/{register}/{schema}?type=commissievergadering` is called
- **THEN** only the 3 commissievergaderingen MUST be returned
- **AND** the facet counts in the response MUST reflect the correct totals per type

#### Scenario: Vergadering status lifecycle
- **GIVEN** a vergadering with status `gepland`
- **WHEN** the status is updated to `bevestigd`
- **THEN** the vergadering MUST be updated successfully
- **AND** when the status is later changed to `afgelast`, the vergadering MUST remain visible in search results with the cancelled status

---

### Requirement: Agendapunt (Agenda Item) schema

The system MUST store agenda items linked to meetings. Agendapunten are the bridge between vergaderingen and all council actions (documents, motions, amendments, votes). The schema MUST support hierarchical sub-agendapunten, ordering within a meeting, and references to related documents via the `bijlagen` array.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-020 | Store agendapunten with: onderwerp, omschrijving, volgorde, vergadering reference | MUST | Done |
| REQ-ORI-021 | Link agendapunt to zero or more raadsdocumenten via the `bijlagen` string array (document slug references) | MUST | Done |
| REQ-ORI-022 | Support parent-child agendapunt hierarchy via `bovenliggendAgendapunt` reference field | SHOULD | Done |
| REQ-ORI-023 | The `onderwerp` property MUST be facetable for search aggregation | MUST | Done |
| REQ-ORI-024 | The `vergadering` reference MUST be required -- every agendapunt MUST belong to exactly one vergadering | MUST | Done |
| REQ-ORI-025 | The `volgorde` property MUST be an integer >= 1, determining the display order within the vergadering | MUST | Done |

#### Scenario: Create agendapunten for a raadsvergadering
- **GIVEN** vergadering `raadsvergadering-2025-09-02` exists
- **WHEN** agendapunten are created:
  - `volgorde`: 1, `onderwerp`: `Opening en mededelingen`
  - `volgorde`: 2, `onderwerp`: `Vaststelling agenda`
  - `volgorde`: 3, `onderwerp`: `Vragenuur`
  - `volgorde`: 4, `onderwerp`: `Voorstel: Herinrichting marktplein`, `bijlagen`: `["besluit-herinrichting-marktplein", "amendement-herinrichting-marktplein", "brief-inwoners-marktplein"]`
  - `volgorde`: 5, `onderwerp`: `Voorstel: Subsidieregeling duurzame energie`
  - `volgorde`: 6, `onderwerp`: `Voorstel: Bestemmingsplan buitengebied`
  - `volgorde`: 7, `onderwerp`: `Ingekomen stukken`
  - `volgorde`: 8, `onderwerp`: `Sluiting`
- **THEN** all 8 agendapunten MUST be linked to the vergadering via the `vergadering` reference field
- **AND** they MUST be retrievable ordered by `volgorde` ascending

#### Scenario: Agendapunt with document references
- **GIVEN** agendapunt `raad-20250902-04-herinrichting-marktplein` has `bijlagen`: `["besluit-herinrichting-marktplein", "amendement-herinrichting-marktplein", "brief-inwoners-marktplein"]`
- **WHEN** the agendapunt is retrieved via the API
- **THEN** the `bijlagen` array MUST contain the document slug references
- **AND** a client MUST be able to resolve each slug to a raadsdocument object in the same register

#### Scenario: List agendapunten for a specific vergadering
- **GIVEN** raadsvergadering `raadsvergadering-2025-09-02` has 8 agendapunten and commissievergadering `commissie-mens-samenleving-2025-09-11` has 4 agendapunten
- **WHEN** `GET /api/objects/{register}/{schema}?vergadering=raadsvergadering-2025-09-02&_order[volgorde]=asc` is called
- **THEN** exactly the 8 agendapunten for that vergadering MUST be returned in order

#### Scenario: Sub-agendapunten hierarchy
- **GIVEN** agendapunt `Voorstel: Herinrichting marktplein` at volgorde 4
- **WHEN** a sub-agendapunt is created with `bovenliggendAgendapunt` referencing the parent
- **THEN** the sub-agendapunt MUST be retrievable as a child of the parent
- **AND** the parent-child relationship MUST be navigable via the API

---

### Requirement: Raadsdocument (Council Document) schema

The system MUST store document metadata for all types of council documents. The raadsdocument schema serves as the unified document model for moties, amendementen, besluiten, brieven, rapporten, and notulen. Each document contains metadata and a reference to the actual file (either a URL or a Nextcloud Files attachment via `FileService`).

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-030 | Store raadsdocumenten with: titel, type, classificatie, url, bestandsnaam, bestandsgrootte, inhoudType | MUST | Done |
| REQ-ORI-031 | Document types: motie, amendement, besluit, brief, rapport, notulen | MUST | Done |
| REQ-ORI-032 | The `titel` and `type` properties MUST be facetable for search and filter UIs | MUST | Done |
| REQ-ORI-033 | Link document file (PDF) via Nextcloud Files integration (`FileService`) when the document is uploaded locally rather than referenced by URL | MUST | Planned |
| REQ-ORI-034 | The `url` property MUST use `format: uri` validation for external document references | MUST | Done |
| REQ-ORI-035 | Support full-text search within document content via `TextExtractionService` for uploaded PDF documents | SHOULD | Planned |
| REQ-ORI-036 | The `classificatie` property MUST be facetable to enable filtering by policy domain (e.g., jeugdzorg, woningbouw, financien, ruimtelijke ordening) | MUST | Done |

#### Scenario: Store a motie document
- **GIVEN** agendapunt `raad-20251021-04-motie-jeugdzorg` exists for the budget debate
- **WHEN** a raadsdocument is created with:
  - `titel`: `Motie: Extra budget jeugdzorg`
  - `type`: `motie`
  - `classificatie`: `jeugdzorg`
  - `url`: `https://voorbeeldstad.nl/raad/documenten/motie-extra-budget-jeugdzorg.pdf`
  - `bestandsnaam`: `motie-extra-budget-jeugdzorg.pdf`
  - `bestandsgrootte`: 124500
  - `inhoudType`: `application/pdf`
- **THEN** the document MUST be stored and publicly accessible
- **AND** the document MUST be findable by searching for "jeugdzorg" via full-text search

#### Scenario: Filter documents by type
- **GIVEN** the ORI register contains documents of types motie (3), amendement (2), besluit (3), brief (3), rapport (2), notulen (2)
- **WHEN** `GET /api/objects/{register}/{schema}?type=motie` is called
- **THEN** only the 3 motie documents MUST be returned
- **AND** facet counts MUST reflect: motie:3, amendement:2, besluit:3, brief:3, rapport:2, notulen:2

#### Scenario: Filter documents by classificatie (policy domain)
- **GIVEN** documents with classificatie values including "jeugdzorg", "woningbouw", "financien", "ruimtelijke ordening"
- **WHEN** `GET /api/objects/{register}/{schema}?classificatie=ruimtelijke%20ordening` is called
- **THEN** all documents classified under "ruimtelijke ordening" MUST be returned (amendement-herinrichting-marktplein, besluit-herinrichting-marktplein, brief-inwoners-marktplein, besluit-bestemmingsplan-buitengebied, brief-provincie-buitengebied)

#### Scenario: Upload document to Nextcloud Files
- **GIVEN** the ORI register has `FileService` integration enabled
- **WHEN** a document PDF is uploaded to agendapunt `raad-20250902-04-herinrichting-marktplein`
- **THEN** the file MUST be stored in Nextcloud Files at path `Open Registers/ORI/raadsdocument/{slug}/`
- **AND** the raadsdocument object MUST be linked to the file via `FileService`
- **AND** the document content MUST be extractable for full-text indexing via `TextExtractionService`

---

### Requirement: Stemming (Vote) schema

The system MUST store voting records with per-fractie breakdowns. The stemming schema captures the outcome of formal votes during raadsvergaderingen on voorstellen, moties, and amendementen. Each stemming records the aggregate counts (voor/tegen/onthoudingen) and a detailed `fractieResultaten` array showing how each party voted.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-040 | Store stemmingen with: onderwerp, type, resultaat, agendapunt reference, stemmenVoor, stemmenTegen, onthoudingen | MUST | Done |
| REQ-ORI-041 | Stemming resultaat values: aangenomen, verworpen | MUST | Done |
| REQ-ORI-042 | Store per-fractie voting breakdown in `fractieResultaten` array with fractie name, stem (voor/tegen/onthouding), and zetels count | MUST | Done |
| REQ-ORI-043 | Stemming type values: voorstel, motie, amendement | MUST | Done |
| REQ-ORI-044 | The `resultaat`, `type`, and `onderwerp` properties MUST be facetable | MUST | Done |
| REQ-ORI-045 | Link stemming to its agendapunt via the `agendapunt` reference field for context navigation | MUST | Done |
| REQ-ORI-046 | Support hoofdelijke stemming (individual per-person votes) via an optional `individueleStemmingen` array for future extension | SHOULD | Planned |
| REQ-ORI-047 | Vote totals (stemmenVoor + stemmenTegen + onthoudingen) SHOULD be validatable against the sum of fractie zetels for consistency checking | SHOULD | Planned |

#### Scenario: Record a vote on a raadsvoorstel
- **GIVEN** agendapunt `raad-20250902-04-herinrichting-marktplein` has been debated
- **WHEN** a stemming is recorded:
  - `onderwerp`: `Voorstel: Herinrichting marktplein Voorbeeldstad`
  - `type`: `voorstel`
  - `resultaat`: `aangenomen`
  - `agendapunt`: `raad-20250902-04-herinrichting-marktplein`
  - `stemmenVoor`: 19, `stemmenTegen`: 16, `onthoudingen`: 0
  - `fractieResultaten`: coalitie (VV 8 voor, GL 6 voor, Dem 5 voor) vs oppositie (LB 5 tegen, PvdA 4 tegen, VVD 3 tegen, SP 2 tegen, Forum 2 tegen)
- **THEN** the stemming MUST be stored with all vote counts and per-fractie breakdown
- **AND** the resultaat MUST be `aangenomen` (19 voor > 16 tegen)

#### Scenario: Record a rejected amendment
- **GIVEN** agendapunt `raad-20251021-05-amendement-ozb` for the OZB amendment
- **WHEN** a stemming is recorded with `resultaat`: `verworpen`, `stemmenVoor`: 10, `stemmenTegen`: 25
- **THEN** the stemming MUST reflect that the opposition amendment was voted down
- **AND** the `fractieResultaten` MUST show that only Lokaal Belang (5), VVD (3), and Forum (2) voted voor

#### Scenario: Unanimous vote
- **GIVEN** motie `Motie: Onderzoek versnelling woningbouw` is put to vote
- **WHEN** all fracties vote voor with `stemmenVoor`: 35, `stemmenTegen`: 0, `onthoudingen`: 0
- **THEN** the stemming MUST show `resultaat`: `aangenomen`
- **AND** all 8 fractieResultaten entries MUST have `stem`: `voor`

#### Scenario: Filter stemmingen by resultaat
- **GIVEN** 6 stemmingen exist: 5 aangenomen, 1 verworpen
- **WHEN** `GET /api/objects/{register}/{schema}?resultaat=verworpen` is called
- **THEN** only the verworpen stemming (amendement-verlaging-ozb) MUST be returned

#### Scenario: Navigate from stemming to vergadering context
- **GIVEN** stemming `stemming-herinrichting-marktplein` has `agendapunt`: `raad-20250902-04-herinrichting-marktplein`
- **WHEN** the agendapunt is resolved via the API
- **THEN** the agendapunt's `vergadering` field MUST point to `raadsvergadering-2025-09-02`
- **AND** a client MUST be able to reconstruct the full chain: stemming -> agendapunt -> vergadering

---

### Requirement: Raadslid (Council Member) schema

The system MUST store council member information linked to their fractie. The raadslid schema covers all persons involved in council proceedings: raadsleden, wethouders, the burgemeester, and the griffier. The schema MUST support active/inactive tracking for historical membership.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-050 | Store raadsleden with: naam, fractie reference (slug), functie, actief (boolean) | MUST | Done |
| REQ-ORI-051 | Functie types: raadslid, wethouder, burgemeester, griffier | MUST | Done |
| REQ-ORI-052 | The `fractie`, `functie`, and `actief` properties MUST be facetable | MUST | Done |
| REQ-ORI-053 | Public API MUST NOT expose BSN, private email, phone number, or home address of raadsleden | MUST | Done (schema contains no private fields) |
| REQ-ORI-054 | Support active/inactive tracking: inactive members (e.g., former raadsleden) MUST remain in the register with `actief: false` for historical reference | MUST | Done |
| REQ-ORI-055 | Track historical fractie membership via start/end dates per raadslid-fractie relation | SHOULD | Planned |

#### Scenario: Register a raadslid
- **GIVEN** fractie "Voorbeeldstad Vooruit" exists with slug `voorbeeldstad-vooruit`
- **WHEN** a raadslid is created:
  - `naam`: `Klaas de Vries`
  - `fractie`: `voorbeeldstad-vooruit`
  - `functie`: `raadslid`
  - `actief`: true
- **THEN** the raadslid MUST be stored and publicly accessible
- **AND** the public API response MUST contain only naam, fractie, functie, and actief -- no private data

#### Scenario: Filter raadsleden by fractie
- **GIVEN** 35 active raadsleden across 8 fracties exist in the mock data
- **WHEN** `GET /api/objects/{register}/{schema}?fractie=groen-links-voorbeeldstad` is called
- **THEN** only raadsleden of Groen Links Voorbeeldstad MUST be returned (Maria Bakker-de Wit as wethouder, Lisa de Groot, Robin Smit, Fatima Bouazza, Jeroen Bos as raadsleden)

#### Scenario: Filter by functie
- **GIVEN** the mock data contains 1 burgemeester, 3 wethouders, 1 griffier, and 28 raadsleden
- **WHEN** `GET /api/objects/{register}/{schema}?functie=wethouder` is called
- **THEN** exactly the 3 wethouders MUST be returned: Maria Bakker-de Wit (GL), Ahmed El-Mansouri (Dem), Petra Koopmans (VV)

#### Scenario: Inactive raadslid remains in register
- **GIVEN** raadslid "Wim van Houten" has `actief: false` (former member)
- **WHEN** `GET /api/objects/{register}/{schema}?actief=true` is called
- **THEN** Wim van Houten MUST NOT appear in the results
- **BUT** when `GET /api/objects/{register}/{schema}` is called without the actief filter
- **THEN** Wim van Houten MUST appear with `actief: false`

---

### Requirement: Fractie (Political Party/Faction) schema

The system MUST store council factions/parties with seat counts and coalition/opposition classification. The fractie schema represents the political groups in the gemeenteraad. Raadsleden reference their fractie by slug, enabling filtering and aggregation of council activities by party.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-060 | Store fracties with: naam, zetels (seat count), classificatie (coalitiepartij/oppositiepartij) | MUST | Done |
| REQ-ORI-061 | The `naam` and `classificatie` properties MUST be facetable | MUST | Done |
| REQ-ORI-062 | Fracties MUST be linkable to their raadsleden via the raadslid.fractie reference field | MUST | Done |
| REQ-ORI-063 | The total zetels across all fracties SHOULD equal the gemeenteraad size (35 in the Voorbeeldstad mock data) | SHOULD | Done |

#### Scenario: Create fracties reflecting a typical Dutch council composition
- **GIVEN** the ORI register for gemeente "Voorbeeldstad"
- **WHEN** fracties are created:
  - Voorbeeldstad Vooruit: 8 zetels, coalitiepartij
  - Groen Links Voorbeeldstad: 6 zetels, coalitiepartij
  - Democraten Voorbeeldstad: 5 zetels, coalitiepartij
  - Lokaal Belang: 5 zetels, oppositiepartij
  - PvdA Voorbeeldstad: 4 zetels, oppositiepartij
  - VVD Voorbeeldstad: 3 zetels, oppositiepartij
  - SP Voorbeeldstad: 2 zetels, oppositiepartij
  - Forum Voorbeeldstad: 2 zetels, oppositiepartij
- **THEN** each fractie MUST be stored and publicly accessible
- **AND** total zetels MUST sum to 35 (19 coalitie + 16 oppositie)

#### Scenario: Filter fracties by classificatie
- **GIVEN** 8 fracties exist: 3 coalitiepartij, 5 oppositiepartij
- **WHEN** `GET /api/objects/{register}/{schema}?classificatie=coalitiepartij` is called
- **THEN** exactly 3 fracties MUST be returned: Voorbeeldstad Vooruit, Groen Links Voorbeeldstad, Democraten Voorbeeldstad

#### Scenario: Derive fractie member count from raadsleden
- **GIVEN** fractie "Voorbeeldstad Vooruit" has slug `voorbeeldstad-vooruit`
- **WHEN** a client queries raadsleden with `fractie=voorbeeldstad-vooruit&actief=true`
- **THEN** the number of matching raadsleden MUST correspond to the fractie's effective membership
- **AND** this count MAY differ from `zetels` if members have left without replacement

---

### Requirement: Demo/mock data for development and testing

The system MUST provide comprehensive seed data representing a realistic Dutch municipality council. The mock data MUST demonstrate all entity relationships and cover realistic council proceedings spanning multiple months. The data MUST be immediately usable for frontend development, API testing, and demonstration purposes.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-070 | Provide mock data for fictional municipality "Voorbeeldstad" in `ori_register.json` | MUST | Done |
| REQ-ORI-071 | Mock data MUST include: 8 fracties, 30+ raadsleden (including burgemeester, wethouders, griffier), 10 vergaderingen (raads- and commissievergaderingen), 30+ agendapunten, 15+ raadsdocumenten, 6+ stemmingen | MUST | Done |
| REQ-ORI-072 | Mock data MUST include both active and inactive raadsleden to demonstrate historical membership | MUST | Done |
| REQ-ORI-073 | Mock data MUST include stemmingen with realistic voting patterns: coalition-wins, opposition-defeats, and unanimous votes | MUST | Done |
| REQ-ORI-074 | Mock data MUST include diverse document types: moties, amendementen, besluiten, brieven, rapporten, notulen | MUST | Done |
| REQ-ORI-075 | Mock data MUST span at least 5 months of council activity to demonstrate date range filtering | MUST | Done |
| REQ-ORI-076 | All mock object slugs MUST follow a consistent, human-readable naming convention | MUST | Done |

#### Scenario: Seed demo data via CLI command
- **GIVEN** a fresh OpenRegister installation
- **WHEN** the admin runs `occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/ori_register.json`
- **THEN** the system MUST create a complete municipality council dataset for "Voorbeeldstad"
- **AND** the register MUST contain approximately 115 objects across 6 schemas
- **AND** the data MUST be immediately browsable via the public API
- **AND** the data MUST demonstrate all entity relationships (vergadering -> agendapunt -> [bijlagen] -> raadsdocument; agendapunt -> stemming with fractieResultaten)

#### Scenario: Mock data covers diverse council activities
- **GIVEN** the ORI mock register is loaded
- **WHEN** the data is inspected
- **THEN** it MUST include realistic council topics: herinrichting marktplein, subsidieregeling duurzame energie, bestemmingsplan buitengebied, begrotingsbehandeling, jeugdzorg, woningbouw, vuurwerkverbod, ICT-aanbesteding, decembercirculaire, jaarrekening
- **AND** the topics MUST span multiple policy domains reflected in raadsdocument classificatie values

#### Scenario: Mock data demonstrates voting patterns
- **GIVEN** the ORI mock register contains 6 stemmingen
- **WHEN** the stemmingen are analyzed
- **THEN** at least one stemming MUST show a close vote (e.g., 19-16 for marktplein herinrichting)
- **AND** at least one stemming MUST show a clear rejection (e.g., 10-25 for OZB amendement)
- **AND** at least one stemming MUST show unanimity (e.g., 35-0 for woningbouw onderzoek motie)
- **AND** coalition/opposition voting patterns MUST be consistent with fractie classificatie

---

### Requirement: Search and filtering across ORI entities

The system MUST support efficient search and filtering across all ORI entities using the existing OpenRegister search infrastructure. All ORI schemas are marked `searchable: true`, enabling full-text search. Facetable properties enable drill-down filtering in citizen-facing search interfaces.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-080 | Full-text search across vergaderingen (naam), agendapunten (onderwerp, omschrijving), raadsdocumenten (titel), stemmingen (onderwerp), raadsleden (naam) | MUST | Done |
| REQ-ORI-081 | Filter by date range on vergaderingen using `startDatum` with `[gte]` and `[lte]` operators | MUST | Planned |
| REQ-ORI-082 | Filter by fractie across raadsleden and derive fractie activity from linked stemmingen | MUST | Done |
| REQ-ORI-083 | Faceted search: expose facets for type, status, classificatie, fractie, resultaat on search results | SHOULD | Planned |
| REQ-ORI-084 | Cross-schema search: a search for "jeugdzorg" MUST return matching agendapunten, raadsdocumenten, and stemmingen | SHOULD | Planned |
| REQ-ORI-085 | Search results MUST include relevance ranking when using full-text search mode | SHOULD | Planned |

#### Scenario: Search for all council activity about a topic
- **GIVEN** the ORI register contains data about jeugdzorg across multiple vergaderingen
- **WHEN** a full-text search for "jeugdzorg" is performed
- **THEN** results MUST include:
  - Agendapunt: `Motie: Extra budget jeugdzorg` (raadsvergadering 21 oktober)
  - Agendapunt: `Bespreking: Stand van zaken jeugdzorg` (commissie 11 september)
  - Raadsdocument: `Motie: Extra budget jeugdzorg` (type: motie)
  - Raadsdocument: `Brief college: Stand van zaken jeugdzorg en wachtlijsten` (type: brief)
  - Stemming: `Motie: Extra budget jeugdzorg` (resultaat: aangenomen)

#### Scenario: Search agendapunten by keyword
- **GIVEN** 30+ agendapunten exist with various onderwerpen
- **WHEN** a full-text search for "bestemmingsplan" is performed on the agendapunt schema
- **THEN** agendapunt `Voorstel: Bestemmingsplan buitengebied` MUST be returned
- **AND** linked raadsdocumenten containing "bestemmingsplan" SHOULD also surface in cross-schema results

#### Scenario: Faceted search on raadsdocumenten
- **GIVEN** 15 raadsdocumenten exist across 6 types and multiple classificaties
- **WHEN** a search is performed with facets enabled
- **THEN** the response MUST include facet counts for `type` (motie:3, amendement:2, besluit:3, brief:3, rapport:2, notulen:2)
- **AND** facet counts for `classificatie` (ruimtelijke ordening:5, jeugdzorg:2, financien:2, etc.)

---

### Requirement: Public access and transparency (Woo compliance)

The system MUST support public, unauthenticated access to council information in line with Wet open overheid (Woo) requirements. All ORI schemas have `authorization.read: ["public"]`, ensuring that council proceedings are transparently accessible to citizens, journalists, and open data aggregators.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-090 | All ORI data MUST be accessible without authentication via the public API | MUST | Done |
| REQ-ORI-091 | Public API MUST support pagination, sorting, and filtering without authentication | MUST | Planned |
| REQ-ORI-092 | Rate limiting MUST be applied to public endpoints to prevent abuse | MUST | Planned |
| REQ-ORI-093 | Public API responses MUST include Cache-Control headers for CDN compatibility | SHOULD | Planned |
| REQ-ORI-094 | The register MUST support bulk export (JSON/CSV) for open data reuse on data.overheid.nl | SHOULD | Planned |
| REQ-ORI-095 | Confidential documents (if any are added beyond the current schema) MUST be filterable by vertrouwelijkheid level | SHOULD | Planned |
| REQ-ORI-096 | The public API MUST comply with DCAT-AP-DONL metadata requirements for publication on data.overheid.nl | SHOULD | Planned |

#### Scenario: Anonymous user browses upcoming vergaderingen
- **GIVEN** 3 upcoming vergaderingen with status `gepland` exist
- **WHEN** an unauthenticated user calls `GET /api/objects/{ori_register_id}/{vergadering_schema_id}?status=gepland&_order[startDatum]=asc`
- **THEN** all 3 vergaderingen MUST be returned with full metadata
- **AND** response headers MUST include appropriate Cache-Control directives
- **AND** no authentication challenge MUST be issued

#### Scenario: Citizen searches council member voting record
- **GIVEN** raadslid "Lisa de Groot" of Groen Links Voorbeeldstad
- **WHEN** an unauthenticated citizen queries stemmingen filtered by fractie involvement
- **THEN** all stemmingen where Groen Links Voorbeeldstad participated MUST be returned
- **AND** the `fractieResultaten` array MUST show how the party voted on each item

#### Scenario: Bulk export for open data portal
- **GIVEN** the ORI register contains 6 months of council data
- **WHEN** an export is requested in JSON format via the `ExportHandler`
- **THEN** all vergaderingen, agendapunten, raadsdocumenten, stemmingen, raadsleden, and fracties MUST be included
- **AND** the export format MUST be compatible with data.overheid.nl publishing requirements (DCAT-AP-DONL)

#### Scenario: Rate limiting on public API
- **GIVEN** a public user makes rapid API requests
- **WHEN** the request rate exceeds the configured threshold
- **THEN** the system MUST return HTTP 429 Too Many Requests
- **AND** the response MUST include a Retry-After header

---

### Requirement: Integration with OpenConnector data sources

The system MUST serve as the data store for council information ingested via OpenConnector connectors from iBabs, NotuBiz, and GO Raadsinformatie systems. The ORI schema field names and types MUST align with iBabs and NotuBiz data models for seamless mapping. Source tracking fields MUST enable traceability and idempotent re-import.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-100 | Schema field names and types MUST align with iBabs and NotuBiz data models for seamless mapping via `MappingService` | MUST | Planned |
| REQ-ORI-101 | Support idempotent upsert: re-importing the same vergadering/agendapunt from iBabs/NotuBiz MUST update, not duplicate | MUST | Planned |
| REQ-ORI-102 | Store source system reference (_sourceSystem, _sourceId, _sourceUrl, _lastSyncedAt) on every imported object for traceability | MUST | Planned |
| REQ-ORI-103 | Support incremental sync: new/changed objects from source systems MUST be mergeable with existing data via the three-stage sync pipeline (per `data-sync-harvesting` spec) | MUST | Planned |
| REQ-ORI-104 | Mapping templates for iBabs-to-ORI and NotuBiz-to-ORI field transformations MUST be provided as Twig mapping definitions | SHOULD | Planned |
| REQ-ORI-105 | Support GO Raadsinformatie as an additional source system alongside iBabs and NotuBiz | SHOULD | Planned |

#### Scenario: Import vergadering from iBabs via OpenConnector
- **GIVEN** an iBabs connector is configured in OpenConnector for municipality "Voorbeeldstad"
- **AND** the connector fetches meeting data from the iBabs API (`/api/meetings`)
- **WHEN** the data is transformed via the iBabs-to-ORI mapping and stored in the ORI register
- **THEN** the vergadering object MUST include `_sourceSystem`: `ibabs` and `_sourceId`: `{ibabs-meeting-id}`
- **AND** a subsequent import of the same vergadering MUST update the existing object (not create a duplicate)
- **AND** the `_lastSyncedAt` timestamp MUST be updated on each sync

#### Scenario: Import from NotuBiz with different field names
- **GIVEN** NotuBiz uses field name `Onderwerp` where iBabs uses `subject`, and NotuBiz uses `Datum` where iBabs uses `startDate`
- **WHEN** the OpenConnector mapping transforms NotuBiz data to ORI schema format
- **THEN** the resulting object MUST use the ORI schema field names (e.g., `onderwerp`, `startDatum`)
- **AND** the source mapping MUST be traceable via `_sourceSystem`: `notubiz`
- **AND** the Twig mapping template MUST handle the field name translation

#### Scenario: Incremental sync detects changes
- **GIVEN** the ORI register was last synced 24 hours ago from iBabs
- **WHEN** the scheduled sync runs and detects 2 new agendapunten and 1 updated vergadering
- **THEN** only the 2 new agendapunten MUST be created and the 1 vergadering MUST be updated
- **AND** unchanged objects MUST NOT be modified
- **AND** the sync log MUST record: "2 created, 1 updated, 47 unchanged"

#### Scenario: Conflict resolution between source and local edits
- **GIVEN** a vergadering was imported from iBabs and subsequently edited locally (e.g., corrected locatie)
- **WHEN** the next sync from iBabs contains an update to the same vergadering
- **THEN** the system MUST apply the configured conflict strategy (source-wins, local-wins, or newest-wins per `data-sync-harvesting` spec)
- **AND** the conflict MUST be logged in the audit trail

---

### Requirement: Multi-gemeente support

The system MUST support hosting ORI data for multiple municipalities within a single OpenRegister instance. Each municipality SHOULD have its own ORI register instance or be distinguishable via the organisatie field. This enables shared hosting scenarios and regional cooperation (gemeenschappelijke regelingen).

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-110 | Support multiple ORI register instances (one per municipality) on a single Nextcloud installation | MUST | Planned |
| REQ-ORI-111 | Each register instance MUST have a unique slug incorporating the municipality identifier (e.g., `ori-voorbeeldstad`, `ori-rotterdam`) | SHOULD | Planned |
| REQ-ORI-112 | The organisatie field on vergaderingen MUST identify the governing body for cross-municipality disambiguation | MUST | Done |
| REQ-ORI-113 | Support CBS gemeentecode (4-digit code) as a standard identifier for organisaties | SHOULD | Planned |

#### Scenario: Two municipalities on one Nextcloud instance
- **GIVEN** a shared Nextcloud installation for a samenwerkingsverband
- **WHEN** ORI registers are provisioned for both "Gemeente Voorbeeldstad" (code 0999) and "Gemeente Nabijdorp" (code 0998)
- **THEN** each municipality MUST have its own register with independent schemas and objects
- **AND** a citizen searching for vergaderingen MUST be able to scope results to their municipality

#### Scenario: Cross-municipality search
- **GIVEN** two ORI registers exist for neighboring municipalities
- **WHEN** a journalist searches for "bestemmingsplan" across both registers
- **THEN** results from both municipalities MUST be returned
- **AND** each result MUST clearly indicate which municipality it belongs to via the organisatie field

#### Scenario: Gemeenschappelijke regeling with shared council data
- **GIVEN** three municipalities participate in a gemeenschappelijke regeling for regional cooperation
- **WHEN** the shared governing body holds a vergadering
- **THEN** the vergadering MUST be storable in a dedicated register for the cooperation body
- **AND** participating municipalities' registers MUST be able to reference it

---

### Requirement: Historical data import and archival

The system MUST support importing historical council data from legacy systems and archive formats. Municipalities switching to OpenRegister from iBabs, NotuBiz, or paper archives need to migrate years of historical proceedings to maintain a complete public record.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-120 | Support bulk import of historical vergaderingen, agendapunten, and stemmingen via the existing `ImportHandler` pipeline | MUST | Planned |
| REQ-ORI-121 | Historical data MUST be importable from CSV, JSON, and XML formats | SHOULD | Planned |
| REQ-ORI-122 | Imported historical records MUST retain their original dates (not use import date) | MUST | Planned |
| REQ-ORI-123 | Historical data MUST be searchable and filterable identically to current-period data | MUST | Planned |
| REQ-ORI-124 | Support importing 4+ years of council data (typical raadsperiode) in a single batch operation | SHOULD | Planned |

#### Scenario: Import 4 years of historical council data from CSV
- **GIVEN** a municipality provides CSV exports of 200 vergaderingen, 3000 agendapunten, and 150 stemmingen from their legacy system
- **WHEN** the data is imported via the bulk import pipeline with appropriate field mappings
- **THEN** all records MUST be created with their original dates preserved
- **AND** the imported records MUST be immediately searchable via the public API
- **AND** the import MUST complete within a reasonable time frame (< 30 minutes for 3000 records)

#### Scenario: Import historical documents
- **GIVEN** a municipality has 500 PDF documents from historical council proceedings
- **WHEN** the documents are uploaded and linked to their corresponding agendapunten
- **THEN** each document MUST be stored in Nextcloud Files and linked via `FileService`
- **AND** document content MUST be extractable for full-text indexing

#### Scenario: Historical data alongside current data
- **GIVEN** 4 years of historical data (2022-2025) and current data (2025-2026) exist in the same register
- **WHEN** a date range query for 2023 is performed
- **THEN** only records from 2023 MUST be returned
- **AND** the response time MUST not be significantly impacted by the total data volume

---

### Requirement: RSS/Atom feed generation for council information

The system SHOULD provide RSS/Atom feeds for council information to enable citizens, journalists, and aggregators to subscribe to updates. Feeds MUST be auto-generated from register data without requiring custom endpoint development.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-130 | Generate an Atom feed of upcoming and recent vergaderingen | SHOULD | Planned |
| REQ-ORI-131 | Generate an Atom feed of new raadsdocumenten (moties, amendementen, besluiten) | SHOULD | Planned |
| REQ-ORI-132 | Generate an Atom feed of stemmingen with outcomes | SHOULD | Planned |
| REQ-ORI-133 | Feeds MUST be publicly accessible without authentication | SHOULD | Planned |
| REQ-ORI-134 | Feeds MUST include proper Atom metadata: title, updated, author, link, content | SHOULD | Planned |

#### Scenario: Citizen subscribes to vergaderingen feed
- **GIVEN** the ORI register has an Atom feed endpoint for vergaderingen
- **WHEN** a citizen adds the feed URL to their RSS reader
- **THEN** they MUST receive updates when new vergaderingen are published or existing ones change status
- **AND** each feed entry MUST include the vergadering naam, datum, locatie, and a link to the full detail page

#### Scenario: Journalist monitors stemmingen feed
- **GIVEN** a journalist subscribes to the stemmingen Atom feed
- **WHEN** a new stemming is recorded after a raadsvergadering
- **THEN** the feed MUST include a new entry with the onderwerp, resultaat, stemmenVoor, stemmenTegen
- **AND** the entry MUST include a summary of the fractieResultaten

#### Scenario: Feed pagination for large datasets
- **GIVEN** the ORI register contains 200+ vergaderingen spanning 4 years
- **WHEN** the vergaderingen Atom feed is requested
- **THEN** only the most recent 20 entries MUST be in the feed
- **AND** an `<link rel="next">` element MUST enable pagination to older entries

---

### Requirement: Data quality validation for ORI objects

The system MUST validate data quality of ORI objects to ensure consistency and completeness. Validation rules MUST catch common data issues from iBabs/NotuBiz imports such as missing references, inconsistent vote totals, and orphaned agendapunten.

| ID | Requirement | Priority | Status |
|----|------------|----------|--------|
| REQ-ORI-140 | Validate that stemmingen vote totals (stemmenVoor + stemmenTegen + onthoudingen) are consistent with fractieResultaten zetels sum | SHOULD | Planned |
| REQ-ORI-141 | Warn when an agendapunt references a non-existent vergadering slug | MUST | Planned |
| REQ-ORI-142 | Warn when a raadslid references a non-existent fractie slug | MUST | Planned |
| REQ-ORI-143 | Validate that bijlagen array entries correspond to existing raadsdocument slugs | SHOULD | Planned |
| REQ-ORI-144 | Report data quality metrics: completeness percentage per schema, referential integrity violations, orphaned objects | SHOULD | Planned |

#### Scenario: Vote totals consistency check
- **GIVEN** a stemming with `stemmenVoor`: 19, `stemmenTegen`: 16, `onthoudingen`: 0, totalling 35
- **AND** `fractieResultaten` with zetels summing to 35 (8+6+5+5+4+3+2+2)
- **WHEN** the stemming is validated
- **THEN** the validation MUST pass: vote totals match fractie zetels sum

#### Scenario: Detect broken agendapunt reference
- **GIVEN** an agendapunt with `vergadering`: `raadsvergadering-2025-99-99` (non-existent slug)
- **WHEN** the validation is run on the ORI register
- **THEN** a warning MUST be reported: "Agendapunt references non-existent vergadering: raadsvergadering-2025-99-99"
- **AND** the agendapunt MUST still be stored (soft validation, per `hardValidation: false`)

#### Scenario: Detect orphaned raadsdocument
- **GIVEN** a raadsdocument exists that is not referenced by any agendapunt's bijlagen array
- **WHEN** a data quality report is generated
- **THEN** the orphaned document MUST be flagged with a warning
- **AND** the report MUST include a list of all unreferenced documents

#### Scenario: Data quality dashboard
- **GIVEN** the ORI register contains 115 objects across 6 schemas
- **WHEN** the admin views the data quality report
- **THEN** the report MUST show: total objects per schema, referential integrity score (% of valid references), completeness score (% of required fields populated), and a list of specific violations

---

