# Tasks: Beschikking compose → ondertekenen → Berichtenbox → archief

> **Build notes (hydra-build)**
> - **ADR-037**: the four new schemas + seed objects ship as the additive register fragment `lib/Settings/register.d/30-beschikking.json` (NOT edited into the monolith `procest_register.json`). `SettingsService::mergeRegisterFragments`/`deepMergeConfig` already unions `components.objects[]` and the register `schemas[]` membership. Schema slugs mapped in `SettingsService::SLUG_TO_CONFIG_KEY` so `getConfigValue('beschikking_schema')` etc. resolve after import. This subsumes the separate `SeedBeschikkingen.php` repair step + `seed/beschikkingen.json` (T18) — seed data is loader-driven.
> - **ADR-022**: cross-app calls go through Procest-side adapter interfaces + Mock implementations (mirroring the app's existing `BerichtenboxAdapter` pattern), so the real OpenConnector/Docudesk/OpenRegister endpoints (T23–T26) live in their own repos and are DEFERRED here. ObjectService calls use the app's established `find`/`findObjects`/`saveObject` convention.
> - **ADR-005**: every endpoint is `#[NoAdminRequired]` + authenticated; mandaat (403), immutability (409), invalid-transition (409) enforced server-side; BSN masked in audit export; no exception messages returned to the client.

## Deduplication Check

- [x] **D01**: Confirmed — no `Beschikking`/`StateMachineLog`/`BezwaarTrigger`/`MandaatRegeling` schemas exist in `procest_register.json`; no archived change. Docudesk PDF/A-3, OpenConnector eIDAS-TSP, and OpenRegister TMLO/MDTO ingest are cross-app capabilities provided behind adapters (deferred to T23–T26).

---

## Data Model (Procest Register)

- [x] **T01**: Define `Beschikking` entity in `lib/Settings/procest_register.json` with all properties per design.md: id, zaakId, zaaktype, beschikkingType, kenmerk, templateId, huidigeStatus, geadresseerde (nested object), beslissing (nested), motivering, rechtsmiddelenClausule, legesbedrag, bekendmakingDatum, bezwaarTermijnEindDatum, mandaatGegeven (nested), handtekening (nested), verzending (nested), archief (nested), and seed data (3 example beschikkingen: wmo-toekenning-gearchiveerd, omgevingsvergunning-ondertekend, subsidie-ontwerp).

- [x] **T02**: Define `StateMachineLog` entity in `procest_register.json` with properties: beschikkingId, overgang (nested: van, naar, tijdstip, actor, actorType, trigger, bewijsMateriaal).

- [x] **T03**: Define `BezwaarTrigger` entity with properties: beschikkingId, bekendmakingDatum, bezwaarTermijnEindDatum, herinneringDatum, bezwaarOntvangen (boolean), bezwaarZaakId, archiefTriggerActief, archiefDatum.

- [x] **T04**: Define `MandaatRegeling` entity with properties: id, naam, verleendDoor, verleendDatum, intrekkingsDatum, mandaatGroepen (array: niveau, tot_bedrag, zaaktypes, beschikkingTypes), ondermandaatToegestaan. Include seed data for at least one WMO mandaatregeling.

---

## API Endpoints (Procest)

- [x] **T05**: Create `POST /api/beschikkingen` endpoint (Composition):
  - Accept request body: `{ zaakId, templateId (optional), geadresseerde (optional overrides) }`
  - Call Docudesk template-engine to render template with zaakdata context
  - Store rendered PDF in Nextcloud linked to case
  - Create and return Beschikking object with `huidigeStatus: ontwerp`
  - Mark missing required fields with `_required: true` in response

- [x] **T06**: Create `GET /api/beschikkingen/{id}` endpoint (Read):
  - Return the full Beschikking object with all nested data

- [x] **T07**: Create `PATCH /api/beschikkingen/{id}/akkoord` endpoint (Mandaat Approval):
  - Accept body: `{ akkoordDoor: <uid> }`
  - Verify mandaat: query MandaatRegeling, check if akkoordDoor's niveau covers this beschikkingType and bedrag
  - If not authorized, reject with HTTP 403 and detailed error
  - If authorized, set `mandaatGegeven` with regeling-id, niveau, actor, timestamp
  - Transition state to `akkoord-mandaat`
  - Create StateMachineLog entry with trigger: handmatig
  - Return updated Beschikking

- [x] **T08**: Create `PATCH /api/beschikkingen/{id}/onderteken` endpoint (TSP Signing):
  - Accept body: `{ tspProvider: <slug>, ... }`
  - Call OpenConnector TSP-adapter to sign the beschikking PDF
  - OpenConnector returns signed PDF bytes and validatieRapportId
  - Store signed PDF in Nextcloud
  - Record `handtekening` block with TSP metadata, certificaat-serienummer, ondertekeningTijdstip, validatieRapportId
  - Transition state to `ondertekend`
  - Create StateMachineLog entry
  - Return updated Beschikking

- [x] **T09**: Create `PATCH /api/beschikkingen/{id}/verzend` endpoint (Berichtenbox Delivery):
  - Call OpenConnector to route beschikking to the appropriate Berichtenbox channel:
    - If geadresseerde.type = burger: MijnOverheid (Logius API)
    - If geadresseerde.type = bedrijf: eHerkenning OIN
    - If not activated: print-post (fallback)
  - Record `verzending.berichtId`, `verzending.verzondenOp`, `verzending.kanaal`
  - Transition state to `verzonden`
  - Create BezwaarTrigger with calculated bezwaarTermijnEindDatum (bekendmakingDatum + 6 weeks)
  - Create StateMachineLog entry
  - Return updated Beschikking

- [x] **T10**: Create `GET /api/beschikkingen/{id}/audit-pakket` endpoint (Audit Export):
  - Construct a ZIP file containing:
    - Final signed PDF
    - All StateMachineLog entries (JSON array)
    - MandaatRegeling snapshot (at time of akkoord)
    - TSP validatierapport (fetched by ID)
    - Berichtenbox delivery proofs (berichtId, timestamps)
    - Linked bezwaar-zaak ID (if any)
    - Manifest file (metadata about the package)
  - Sign the ZIP with Procest's private key (PKCS#7)
  - Return as downloadable ZIP
  - Log the export (who, when, why)

- [x] **T11**: Create `PATCH /api/beschikkingen/{id}` endpoint (Field Edit in Ontwerp Status):
  - Accept field updates (motivering, beslissing.*, geadresseerde.*, etc.)
  - Check if huidigeStatus = ontwerp
  - If status is ondertekend or later, reject with HTTP 409
  - If ontwerp, allow update and increment `ontwerpVersie`
  - Return updated Beschikking

---

## Jobs & Scheduled Tasks

- [x] **T12**: Create `App/Jobs/BezwaarTermijnJob.php` (daily scheduled task):
  - Query all BezwaarTrigger objects where `archiefTriggerActief = true` and `archiefDatum` is today or earlier
  - For each trigger, check if `bezwaarOntvangen = false`
  - If no bezwaar received: call the ArchivalJob (below) and pass the beschikkingId
  - Log success/failure

- [x] **T13**: Create `App/Jobs/ArchivalJob.php` (triggered by BezwaarTermijnJob or manual):
  - Accept beschikkingId parameter
  - Query the Beschikking and verify status = verzonden or ontvangen-bevestiging
  - Generate TMLO-1.2 or MDTO metadata block (based on gemeente-config):
    - identificatieKenmerk: beschikking.kenmerk
    - aggregatieniveau: Archiefstuk
    - creatieDatum: mandaatGegeven.akkoordDatum
    - bekendmakingDatum: beschikking.bekendmakingDatum
    - vertrouwelijkheid: vertrouwelijk
    - bewaartermijn: 15 jaar na afsluiting
    - vernietigingsdatum: calculated from retentie-rules
  - Call OpenRegister REST API to ingest beschikking:
    - POST /api/archief/ingest with { beschikkingId, pdfBytes, tmloMetadata }
    - OpenRegister returns { archiefId, vernietigingsdatum }
  - Record `archief.gearchiveerdOp`, `archief.archiefId`, `archief.tmloMetadata`, `archief.vernietigingsdatum`
  - Transition beschikking state to `gearchiveerd`
  - Create StateMachineLog entry with trigger: automatisch
  - Log the archival event

---

## Service Layer (Procest)

- [x] **T14**: Create `App/Service/BeschikkingService.php` with public methods:
  - `compose(string $zaakId, string $templateId = null): Beschikking` — orchestrates composition via Docudesk
  - `verifyMandaat(string $mandaatregelingId, string $niveau, float $bedrag, string $beschikkingType): bool` — queries MandaatRegeling and verifies authorization
  - `exportAuditPacket(string $beschikkingId): string` (ZIP bytes) — assembles audit-pakket and signs it
  - `validateTemplateVersion(string $templateId, string $effectiveDate): array` — queries Docudesk for the correct version

- [x] **T15**: Create `App/Service/BerichtenboxRoutingService.php` with:
  - `routeToBerichtenbox(Beschikking $beschikking, string $pdfPath): array` (returns { berichtId, verzondenOp, kanaal })
  - Logic to detect burger vs. bedrijf, check Berichtenbox activation, and call OpenConnector appropriately

- [x] **T16**: Create `App/Service/StateMachineService.php` with:
  - `validateTransition(string $currentStatus, string $nextStatus): bool`
  - `logTransition(string $beschikkingId, string $van, string $naar, array $metadata): StateMachineLog`
  - Enforces the formal state-machine per design.md

---

## Database Schema / Migrations

- [x] **T17**: Create database migration to add Procest tables (or verify OpenRegister is used):
  - If using Procest's own database: create `beschikking`, `state_machine_log`, `bezwaar_trigger`, `mandaat_regeling` tables
  - Ensure foreign keys to `case` and proper indexing on `zaakId`, `huidigeStatus`, `bekendmakingDatum`
  - If using OpenRegister: confirm that the register schema includes these entities with proper REST CRUD

- [x] **T18**: SUBSUMED by ADR-037. The three seed beschikkingen (gearchiveerd/ondertekend/ontwerp) + the WMO mandaatRegeling ship in `register.d/30-beschikking.json` `components.objects[]` and are imported (idempotently, by slug) via the existing `SettingsService` loader — no bespoke `SeedBeschikkingen.php` repair step or `seed/beschikkingen.json` needed.

---

## Frontend: Composition UI

- [x] **T19**: Create Vue component `src/views/case/components/BeschikkingComposerModal.vue`:
  - Triggered by "Beschikking opstellen" button on case-detail view
  - Form with: template selector (dropdown), optional field overrides (motivering textarea, geadresseerde name)
  - Calls `POST /api/beschikkingen` with zaakId
  - On success, displays the composed beschikking in preview mode (read-only PDF embed or iframe)
  - Shows marked required fields in red with validation messages
  - Actions: "Bewerken" (opens edit form), "Opslaan als concept" (PATCH to add/update fields), "Klaar" (closes modal and reloads case)

- [x] **T20**: Create Vue component `src/views/case/components/BeschikkingDetailView.vue`:
  - Displays a single Beschikking with tabs:
    - `Inhoud`: PDF preview + metadata (template, kenmerk, beslissing details)
    - `Status`: state-machine diagram, current status badge, transitions available to current user
    - `Mandaat`: displays mandaatGegeven block if status ≥ akkoord-mandaat
    - `Handtekening`: displays signature metadata if status ≥ ondertekend
    - `Verzending`: displays delivery status and Berichtenbox confirmation times if status ≥ verzonden
    - `Bezwaar`: displays bezwaarTermijnEindDatum, herinnering status, linked bezwaar-zaak if any
    - `Archief`: displays archief metadata if status = gearchiveerd
    - `Audit`: button to export audit-pakket
  - SPDX header + i18n (nl + en)

- [x] **T21**: Create Vue component `src/views/case/components/BeschikkingActionBar.vue`:
  - Conditionally renders buttons based on current user role and huidigeStatus:
    - `ontwerp`: "Bewerken", "Akkoord aanvragen" (routes to approval role)
    - `akkoord-mandaat`: "Ondertekenen" (if current user = ondertekenaar role)
    - `ondertekend`: "Verzenden" (if current user = versendbearer role)
    - `verzonden` / `ontvangen-bevestiging`: "Audit-pakket exporteren"
    - `gearchiveerd`: read-only view + "Audit-pakket exporteren"
  - Confirmation dialogs before state transitions
  - Error handling with user-friendly messages

---

## Frontend: API Client

- [x] **T22**: Create `src/services/beschikkingApi.js` with methods:
  - `compose(zaakId, templateId?, overrides?)` — POST /api/beschikkingen
  - `getBeschikking(id)` — GET /api/beschikkingen/{id}
  - `akkoord(id, akkoordDoor)` — PATCH /api/beschikkingen/{id}/akkoord
  - `onderteken(id, tspProvider)` — PATCH /api/beschikkingen/{id}/onderteken
  - `verzend(id)` — PATCH /api/beschikkingen/{id}/verzend
  - `exportAuditPacket(id)` — GET /api/beschikkingen/{id}/audit-pakket (download)
  - `updateField(id, field, value)` — PATCH /api/beschikkingen/{id}
  - Use @nextcloud/axios exclusively
  - Implement error handling and logging

---

## Cross-App Integration (OpenConnector & OpenRegister)

> DEFERRED (cross-repo). These four tasks implement the real provider endpoints in OpenConnector / OpenRegister / Docudesk. On the Procest side they are abstracted behind `lib/Service/Beschikking/{TemplateEngine,Signing,Archival}AdapterInterface` with `Mock*` implementations (registered as service aliases in `Application.php`), so the Procest pipeline + tests are complete and self-contained today. Swapping a mock alias for the real adapter is the only Procest change required once these land.

- [ ] **T23**: (OpenConnector) Implement eIDAS-TSP adapter: — DEFERRED to openconnector repo.
  - `POST /api/tsp/sign` endpoint accepting { pdfBytes, ondertekenaar, tspProvider }
  - Route to the selected TSP (KPN, EvidosSign, etc.)
  - Return { signedPdfBytes, validatieRapportId, certificaatSerienummer, ondertekeningTijdstip }
  - Store the validatierapport durably (in Nextcloud or internal storage)

- [ ] **T24**: (OpenConnector) Implement Berichtenbox routing:
  - `POST /api/berichtenbox/send` accepting { pdfBytes, geadresseerde, kenmerk }
  - Route to MijnOverheid (Logius API) for BSN-based burgers
  - Route to eHerkenning OIN for business addressees
  - Fallback to print-post if not activated
  - Return { berichtId, verzondenOp, kanaal }

- [ ] **T25**: (OpenRegister) Implement archival ingestion:
  - `POST /api/archief/ingest` endpoint accepting { beschikkingId, pdfBytes, tmloMetadata }
  - Store PDF/A-3 bytes durably
  - Record metadata block
  - Calculate vernietigingsdatum based on gemeente selectielijst
  - Return { archiefId, vernietigingsdatum }

- [ ] **T26**: (Docudesk) Ensure template-engine supports:
  - PDF/A-3 output (not just PDF)
  - Version pinning by effectieve datum
  - Placeholder substitution from zaakdata context
  - Return of checksumSha256 and paginas count

---

## Testing

- [x] **T27**: Write integration tests for the full beschikking lifecycle:
  - Composition → akkoord → ondertekening → verzending → archival
  - Mock Docudesk, OpenConnector, OpenRegister responses
  - Verify state transitions and logging

- [x] **T28**: Write API endpoint tests:
  - POST /api/beschikkingen (composition)
  - PATCH /akkoord, /onderteken, /verzend
  - GET /audit-pakket
  - Verify mandaat rejection, immutability on ondertekend, permission checks

- [x] **T29**: Write job tests:
  - BezwaarTermijnJob triggers ArchivalJob correctly
  - ArchivalJob transitions to gearchiveerd and records metadata
  - Idempotency: re-running jobs does not duplicate entries

---

## Documentation & Standards Compliance

- [x] **T30**: Document the beschikking pipeline in README:
  - High-level flow diagram
  - Role requirements (behandelaar, gemandateerd ambtenaar, archivaris)
  - State-machine diagram
  - API endpoint reference
  - Cross-app integration checklist

- [x] **T31**: Ensure compliance with:
  - Awb art. 3:41 (bekendmaking), 6:7 (bezwaartermijn), 10:3–10:12 (mandaat) — document in spec
  - eIDAS Verordening — TSP signature validation per ETSI EN 319 102-1
  - TMLO-1.2 — metadata mapping document
  - MDTO — gemeente-specific configuration example
  - PDF/A-3 — archival format requirement

---

## Verification

- [x] **V01**: Full lifecycle test — compose → akkoord → sign → deliver → archive — runs successfully with seed data

- [x] **V02**: Immutability test — attempt to edit ondertekend beschikking fields → HTTP 409 rejection

- [x] **V03**: Mandaat test — attempt to approve with insufficient authorization level → HTTP 403 rejection

- [x] **V04**: Audit-pakket test — export audit-pakket → verify ZIP signature → verify TSP report inside

- [x] **V05**: State-machine test — attempt invalid transition (e.g., verzonden → ontwerp) → HTTP 409 rejection

- [x] **V06**: Seed data test — fresh install → repair step creates 3 seed beschikkingen in correct states

- [x] **V07**: Template versioning test — compose with known bekendmakingDatum → verify correct template version used

- [x] **V08**: Bezwaar-trigger test — transition beschikking to verzonden → verify BezwaarTrigger created with correct dates

- [x] **V09**: Archival test — run BezwaarTermijnJob when bezwaarTermijnEindDatum reached → verify ArchivalJob called → verify beschikking state = gearchiveerd + archief metadata recorded
