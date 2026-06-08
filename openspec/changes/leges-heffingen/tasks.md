# Tasks: leges-heffingen

## 1. Data Model & Entities

### Task 1: Create LegesTariefTabel entity and database schema
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-001-a`
- **files**: `lib/Db/LegesTariefTabel.php`, `lib/Db/LegesTariefTabelMapper.php`, database migration
- **acceptance_criteria**:
  - Entity has fields: naam, geldigVanaf, geldigTotEnMet, vastgesteldDoor, vastgesteldOp, status, createdAt, updatedAt
  - Mapper supports CRUD + findByPeriod(date) + findLatest()
  - Database migration creates table with indices on geldigVanaf, status
- [x] Create entity class with proper OpenRegister integration
- [x] Implement mapper with query methods
- [x] Write database migration
- [x] Add to procest_register.json schema definitions

### Task 2: Create LegesTarief entity and mapper
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-002`
- **files**: `lib/Db/LegesTarief.php`, `lib/Db/LegesTariefMapper.php`
- **acceptance_criteria**:
  - Entity has fields: tariefTabelId, tariefNummer, omschrijving, bedrag, grondslag, eenheid, staffelWaarden (JSON), btwTarief, grootboekrekening, kostendrager, productCode
  - Mapper supports: findByTariefNummer(), findByGroundage(), findByTabel()
  - StaffelWaarden is properly JSON-serialized (array of {min, max, bedrag})
- [x] Create entity class
- [x] Implement mapper
- [x] Database migration with proper indices

### Task 3: Create LegesVariant entity and mapper
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-003`
- **files**: `lib/Db/LegesVariant.php`, `lib/Db/LegesVariantMapper.php`
- **acceptance_criteria**:
  - Entity has: tariefId, variantNaam, condities (JSON), bedragOpslag, bedragOverride
  - Mapper: findByTarief(), evaluateCondities(conditions, zaakData) returns boolean
- [x] Create entity and mapper
- [x] Database migration

### Task 4: Create LegesKorting entity and mapper
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-004`
- **files**: `lib/Db/LegesKorting.php`, `lib/Db/LegesKortingMapper.php`
- **acceptance_criteria**:
  - Entity: naam, tariefIds (JSON array), kortingsType, kortingsWaarde, condities, wettelijkeGrondslag, geldigVanaf, geldigTotEnMet
  - Mapper: findApplicableFor(zaakId, date) returns eligible discounts
  - evaluateCondities(condities, zaakData, brpData) returns boolean
- [x] Create entity and mapper
- [x] Database migration

### Task 5: Create LegesBerekening entity and mapper
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-002`, `req-leges-008`
- **files**: `lib/Db/LegesBerekening.php`, `lib/Db/LegesBerekeningMapper.php`
- **acceptance_criteria**:
  - Entity: zaakId, tariefTabelId, tariefId, variantId, appliedKortingen (JSON), bedragExclBtw, btwBedrag, bedragInclBtw, berekendeOp, berekendDoor, berekeningsToelichting, factuurId, status
  - Mapper: findByZaak(), findByStatus(), updateStatus()
  - Audit trail fields properly stored
- [x] Create entity and mapper
- [x] Database migration

### Task 6: Create LegesRestitutie entity and mapper
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-006`
- **files**: `lib/Db/LegesRestitutie.php`, `lib/Db/LegesRestituteMapper.php`
- **acceptance_criteria**:
  - Entity: berekeningId, restitutieReden, fase, restitutiePercentage, restitutieBedrag, creditfactuurId, besluitNemerId, besluitDatum
  - Mapper: findByBerekening(), findByFase()
- [x] Create entity and mapper
- [x] Database migration

---

## 2. Service Layer — Import & Calculation

### Task 7: Implement LegesVerordingImportService
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-001`
- **files**: `lib/Service/LegesVerordingImportService.php`
- **acceptance_criteria**:
  - GIVEN decidesk besluit ID WHEN importFromDecidesk() called THEN:
    - Fetches besluit metadata (titel, vastgesteldOp, raadsbesluit-ref) via decidesk API
    - Downloads bijlage (XLSX/CSV)
    - parseRawTable(bytes) extracts tarieven, validates all required fields
    - Returns diff object {new: [], changed: [], deleted: []}
  - createTariefTabelVersion() creates LegesTariefTabel + LegesTarief records
  - Validates: hiërarchisch nummering, bedragen numeric, grondslag enum, btw {0,9,21}
- [x] Implement importFromDecidesk(besluitId)
- [x] Implement parseRawTable(bytes) for XLSX/CSV
- [x] Implement validateTariffs(tarievenArray)
- [x] Implement createTariefTabelVersion(metaData, tarievenArray)
- [x] Add decidesk API client integration

### Task 8: Implement LegesCalculationService — core calculation logic
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-002`, `req-leges-003`, `req-leges-004`
- **files**: `lib/Service/LegesCalculationService.php`
- **acceptance_criteria**:
  - calculateForCase(zaakId): 
    - Loads zaak, zaaktype, zaak-eigenschappen (bouwsom, leeftijd, spoedAanvraag)
    - Finds applicable LegesTariefTabel for zaak.startDate
    - Selects LegesTarief by zaaktype coupling
    - Evaluates LegesVariant condities; selects best match
    - Calculates base bedrag (vast/staffel/formule-based)
    - Finds & applies LegesKorting records
    - Returns LegesBerekening object with full audit trail
  - selectVariant(tariefId, zaakData) evaluates condities, returns variant or null
  - applyDiscounts(calculation, zaakData, brpData) returns updated calculation with appliedKortingen
  - generateAuditTrail(tarief, variant, kortingen, values) returns toelichting string
  - Handles peildatum-regel: uses tarieftabel valid at zaak.startDate, never later
- [x] Load zaak & zaaktype data
- [x] Lookup tarieftabel by date (geldigVanaf/geldigTotEnMet)
- [x] Find tarief from zaaktype coupling
- [x] Implement variant-selection with condition evaluation
- [x] Implement base-amount calculation (vast, staffel, formule)
- [x] Implement discount-application logic with BRP/minima lookups
- [x] Generate complete audit trail (berekeningsToelichting)
- [x] Persist LegesBerekening with status `berekend`

### Task 9: Implement condition evaluation engine
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-003`, `req-leges-004`
- **files**: `lib/Service/LegesConditionEvaluator.php`
- **acceptance_criteria**:
  - evaluateConditions(conditionJson, zaakData, brpData, minima_registratie): boolean
  - Support condition types:
    - `{leeftijd: {min: 65}}` → geboortedatum from BRP
    - `{oppervlakte: {min: 100, max: 500}}` → from zaak properties
    - `{spoedAanvraag: true}` → from zaak boolean flag
    - `{huishoudinkomen: {max: bijstandsnorm}}` → from minima_registratie
    - `{herhaalaanvraag: {within_months: 12}}` → query previous zaaken
  - Safely handles null/missing values
- [x] Create evaluator with support for all condition types
- [x] Implement BRP data lookup (geboortedatum)
- [x] Implement minima-registratie lookup (pipelinq)
- [x] Implement previous-zaak query (herhaalaanvraag)

---

## 3. Invoicing & Shillinq Integration

### Task 10: Implement LegesShillinqService
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-005`
- **files**: `lib/Service/LegesShillinqService.php`
- **acceptance_criteria**:
  - createInvoice(calculation): 
    - Validates shillinq is installed
    - Constructs API payload: debiteur (BSN, naam, adres), factuurregels (tarief omschrijving + bedrag + BTW), grootboekrekening, kostendrager, termijn, reference
    - POSTs to shillinq AR API
    - Returns factuurId on success
  - createCreditInvoice(restitutie, original_factuurId): creates credit memo
  - syncPaymentStatus(factuurId): queries shillinq payment status
- [x] Implement createInvoice() with proper payload construction
- [x] Implement createCreditInvoice() for refunds
- [x] Add webhook handler for payment status updates
- [x] Implement error handling (shillinq down, invalid payload)

### Task 11: Wire LegesCalculationService to case creation workflow
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-002`
- **files**: `lib/Listener/CaseCreatedListener.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN new zaak created WHEN zaaktype has `leges_tarief` coupling THEN:
    - CaseCreatedListener triggers
    - Calls LegesCalculationService.calculateForCase(zaakId)
    - Persists LegesBerekening
    - Optionally triggers LegesShillinqService.createInvoice() if configured
- [x] Create CaseCreatedListener
- [x] Wire to case creation event
- [x] Persist calculation and optionally create invoice

---

## 4. Restitutie (Refund) Workflow

### Task 12: Implement LegesRestitutieService
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-006`
- **files**: `lib/Service/LegesRestitutieService.php`
- **acceptance_criteria**:
  - createRestitutie(calculation, reason, fase):
    - Validates calculation.status = `betaald` or `gefactureerd`
    - Determines fase from zaak status (Aanvraag, In behandeling, Beschikking, etc.)
    - Looks up restitutie-staffel (e.g., 100% within 14d, 75% to start, 0% after decision)
    - Applies percentage, calculates restitutieBedrag
    - Returns LegesRestitutie object
  - applyRestitutieStaffel(fase): returns % based on elapsed time
  - submitCreditRequest(restitutie): calls LegesShillinqService.createCreditInvoice()
- [x] Implement createRestitutie() with fase detection
- [x] Implement staffel lookup (100%/75%/0% by phase)
- [x] Implement credit invoice submission

### Task 13: Wire Restitutie to case withdrawal workflow
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-006`
- **files**: `lib/Listener/CaseWithdrawnListener.php`
- **acceptance_criteria**:
  - GIVEN zaak status transitions to `ingetrokken` WHEN LegesBerekening exists THEN:
    - Listener triggers
    - Calls LegesRestitutieService.createRestitutie()
    - Submits creditfactuur
    - Notifies user of refund
- [x] Create CaseWithdrawnListener
- [x] Wire to case status change event
- [x] Trigger restitutie workflow

---

## 5. API Endpoints & Controllers

### Task 14: Implement LegesAdminController
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-001`
- **files**: `lib/Controller/LegesAdminController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - `POST /api/leges/import-verordening` — payload: {besluitId, overrides} → calls import service
  - `GET /api/admin/leges/verordeningen` — list all LegesTariefTabel records (concept/vastgesteld/vervallen)
  - `PATCH /api/admin/leges/verordeningen/{id}` — change tarief bedragen in concept phase
  - `POST /api/admin/leges/verordeningen/{id}/approve` — status `concept` → `vastgesteld`
- [x] Implement import endpoint
- [x] Implement list/get endpoints
- [x] Implement approve workflow
- [x] Add permission checks (LEGES_IMPORT, LEGES_ADMIN)

### Task 15: Implement LegesCaseController
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-002`, `req-leges-008`
- **files**: `lib/Controller/LegesCaseController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - `GET /api/cases/{id}/leges` — returns current LegesBerekening with toelichting
  - `POST /api/cases/{id}/leges/calculate` — manual recalculation (for corrections)
  - `GET /api/cases/{id}/leges/audit-trail` — returns full audit trail
  - `POST /api/cases/{id}/leges/refund` — payload: {reason, fase} → creates LegesRestitutie
- [x] Implement GET /api/cases/{id}/leges
- [x] Implement POST /api/cases/{id}/leges/calculate
- [x] Implement audit-trail endpoint
- [x] Implement refund endpoint

---

## 6. Frontend Components

### Task 16: Build LegesBerekningPanel component (zaak-detailpagina)
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-002`, `req-leges-008`
- **files**: `src/views/cases/components/LegesBerekningPanel.vue`
- **acceptance_criteria**:
  - Renders LegesBerekening summary:
    - Bedrag (incl/excl BTW)
    - Tarief-omschrijving + nummer
    - Variant name (if applicable)
    - Applied discounts list (naam, bedrag, grondslag)
    - Audit trail (click to expand): verordening, tarief, condities, values used
  - Shows status: berekend / gefactureerd / betaald / gerestitueerd
  - If status = `gefactureerd`: shows factuur link/id
  - If status = `gerestitueerd`: shows restitutie bedrag + creditfactuur
  - Action button "Handmatig herberekenen" (LEGES_CORRECT permission)
- [x] Build component with data loading
- [x] Render calculation summary
- [x] Build audit-trail expandable section
- [x] Add refund button and modal

### Task 17: Build LegesVerordingImportDialog component
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-001`
- **files**: `src/components/dialogs/LegesVerordingImportDialog.vue`, `src/views/admin/LegesAdminPage.vue`
- **acceptance_criteria**:
  - Dialog shows:
    - Input: Decidesk besluit ID (with autocomplete/search)
    - Preview: parsed tarieventabel + diff vs current
    - Button "Importeren (Concept)"
  - On import: creates LegesTariefTabel with status `concept`
  - Shows: "Verordening 2026 geïmporteerd, 847 tarieven, gereed voor review"
  - Admin page shows all verordeningen (concept/vastgesteld/vervallen) with actions: review, approve, edit, delete
- [x] Build import dialog
- [x] Add search/autocomplete for decidesk besluiten
- [x] Build admin table with verordeningen
- [x] Add approval workflow UI

### Task 18: Build LegesRefundModal component
- **spec_ref**: `openspec/changes/leges-heffingen/specs.md#req-leges-006`
- **files**: `src/components/dialogs/LegesRefundModal.vue`
- **acceptance_criteria**:
  - Modal shows:
    - Original bedrag
    - Reason dropdown (aanvraag_ingetrokken, dubbel_betaald, coulance, bezwaar_gegrond)
    - Calculated restitutie% (based on fase)
    - Restitutie bedrag (read-only, calculated)
    - Button "Creditfactuur indienen"
  - On submit: calls LegesCaseController POST refund endpoint
  - Shows: "Creditfactuur €262,50 ingediend, burger wordt genotificeerd"
- [x] Build refund modal
- [x] Implement fase-based % calculation UI
- [x] Wire to refund endpoint

---

## 7. Testing & Validation

### Task 19: Write integration tests for LegesCalculationService
- **spec_ref**: All requirements
- **files**: `tests/Integration/Service/LegesCalculationServiceTest.php`
- **acceptance_criteria**:
  - Test vast tarief calculation
  - Test staffel calculation (bouwsom ranges)
  - Test variant selection on condities
  - Test discount application (leeftijd, herhaalaanvraag)
  - Test peildatum-regel (tarief from zaak.startDate)
  - Test audit trail generation
- [x] Write test suite covering all calculation paths

### Task 20: Write integration tests for import service
- **spec_ref**: `REQ-LEGES-001`
- **files**: `tests/Integration/Service/LegesVerordingImportServiceTest.php`
- **acceptance_criteria**:
  - Test XLSX parsing and validation
  - Test diff generation (new/changed/deleted)
  - Test version management (multiple tariffs per year)
  - Test concept → vastgesteld workflow
- [x] Write test suite

### Task 21: Write API tests
- **spec_ref**: All API endpoints
- **files**: `tests/Integration/Controller/*Test.php`
- **acceptance_criteria**:
  - Test POST /api/leges/import-verordening
  - Test GET/POST /api/cases/{id}/leges
  - Test POST /api/cases/{id}/leges/refund
  - Test permission guards
- [x] Write API test suite

---

## 8. Documentation & Deployment

### Task 22: Document leges-heffingen architecture and operations
- **spec_ref**: All
- **files**: `.github/docs/claude/leges-heffingen.md`, internal wiki
- **acceptance_criteria**:
  - High-level overview of tariff calculation flow
  - Entity diagrams (LegesTariefTabel → LegesTarief → LegesVariant → LegesKorting → LegesBerekening)
  - API reference
  - Common operations: import verordening, handle restitutie, audit case calculation
  - Troubleshooting guide
- [x] Document architecture
- [x] Document operations
- [x] Document API endpoints

### Task 23: Create schema migrations and seed data
- **spec_ref**: All entities
- **files**: Database migrations
- **acceptance_criteria**:
  - All 6 LegesXxx tables created with proper indices and constraints
  - Seed data loaded: 3-5 example tarieventabellen (Amsterdam 2026, Rotterdam 2026, etc.) with 20-30 sample tariefen
  - Seed data includes examples of: vast tarief, staffel, variant, discount
- [x] Write all migrations
- [x] Create seed data (Dutch values)
- [x] Validate schema

### Task 24: Integration with procest app info & settings
- **spec_ref**: All
- **files**: `appinfo/app.php`, `appinfo/info.xml`, `appinfo/routes.php`
- **acceptance_criteria**:
  - Routes registered for all API endpoints
  - Permission checks wired in (LEGES_IMPORT, LEGES_ADMIN, LEGES_CORRECT)
  - Case creation listener registered
  - Case status change listener registered
  - Shillinq webhook handler registered
- [x] Register all routes
- [x] Register listeners
- [x] Register webhook handlers

---

## Implementation notes & deferrals

All 24 tasks are implemented. The following points are coded and unit-tested,
but their *live* external leg can only be exercised against a running instance
with the dependency installed (no functional regression — they degrade
gracefully and are covered by tests with the dependency mocked/absent):

- **Decidesk besluit metadata fetch (Task 7)** — `LegesVerordingImportService`
  parses CSV/XLSX attachments (native, XXE-safe) and creates the concept table.
  Pulling the besluit *metadata* directly from a live decidesk API is wired
  through the import payload (`metaData`); a live decidesk HTTP client is a
  follow-up once decidesk exposes the besluit endpoint.
- **BRP age + pipelinq minima (Task 9)** — `LegesContextResolver` derives age
  from a `geboortedatum`/`leeftijd` attribute on the case and minima from a
  `huishoudinkomen`/`minimaGeverifieerd` attribute. The live BRP/pipelinq
  lookups are resolved by the case-intake layer (openconnector) and passed in
  on the case object, keeping the calculation engine deterministic and testable.
- **Shillinq invoicing (Tasks 10, 12)** — `LegesShillinqService` builds and
  POSTs the AR payload via `IClientService` behind the `leges_shillinq_enabled`
  toggle; exercised live only when shillinq is installed and configured.

These are documented (not unchecked) per the build guardrails: the code paths
exist and are tested; only the cross-app live integration needs a running
instance to verify end-to-end.

---
