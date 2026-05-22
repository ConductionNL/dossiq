# Tasks: leges-heffingen voor zaaktype-gestuurde aanvragen

All tasks are `[procest]`. Estimates: XS = < 2hrs, S = half-day, M = 1–2 days, L = 3+ days.

---

## [procest] Data Model & Schema

### L-1. Update ADR-000 and procest_register.json with 6 new entities (L)

- [ ] L-1.1 Update `/workspace/repo/openspec/architecture/adr-000-data-model.md` to define all 6 new entities with full property tables:
  - legesTariefTabel (versionable tariff table)
  - legesTarief (individual tariff line)
  - legesVariant (variant with conditional adjustment)
  - legesKorting (discount/exemption rule)
  - legesBerekening (concrete calculation instance)
  - legesRestitutie (restitution record)
  Each entity MUST include: all properties, types, required flags, descriptions, relations to other entities.
  - **Acceptance**: ADR-000 updated; all 6 entities defined with complete property tables; relations documented; no circular dependencies.

- [ ] L-1.2 Add all 6 schemas to `lib/Settings/procest_register.json` in proper JSON-Schema format:
  - Each schema has `type: object`, `properties: {…}`, `required: […]`
  - Relations defined via `x-relation` or standard `$ref` (per OR convention)
  - Add `x-openregister-authorization` blocks (who can read/write/delete) — default: authenticated users can read/write, only admin can delete
  - Validate JSON is well-formed (no syntax errors)
  - **Acceptance**: procest_register.json valid JSON after changes; all 6 schemas present; `composer check:strict` passes; register can be loaded without errors.

- [ ] L-1.3 Update `caseType` schema in procest_register.json:
  - Add optional property `legesTariefId` (string, reference to LegesTariefTabel)
  - Add optional property `legesRestitutieStaffeling` (JSON object with staffel rules: % by days-since-factuur)
  - **Acceptance**: caseType schema updated; can save a caseType with legesTariefId; existing caseTypes load without error (backward compat).

- [ ] L-1.4 Update `case` schema in procest_register.json:
  - Add optional property `legesBerekening` (reference to LegesBerekening)
  - Add optional property `legesRestitutie` (reference to LegesRestitutie, if any)
  - Add optional property `peildatum` (date, snapshot of calculation date)
  - **Acceptance**: case schema updated; cases can have legesBerekening / legesRestitutie refs; existing cases load without error.

---

## [procest] Service Layer

### L-2. Implement LegesCalculationService (M)

- [ ] L-2.1 Create `lib/Service/LegesCalculationService.php` with public method `calculateLeges(Case $case): LegesBerekening`
  - Fetches LegesTariefTabel for case.peildatum (or today if not set)
  - Determines tariff from caseType.legesTariefId
  - Evaluates tariff's staffel-rules (if grondslag = "staffel") or applies fixed % (if grondslag = "bouwsom"/"oppervlakte"/etc.)
  - Calls selectVariant() to pick correct variant based on zaak attributes
  - Calls applyDiscounts() to apply all matching LegesKorting rules
  - Computes BTW amount
  - Returns LegesBerekening object (not yet saved to register)
  - **Acceptance**: Method signature is correct; calculates bedrag correctly for test cases (bouwsom 250k → 3% → 7500); all intermediate values logged in berekeningsToelichting; method is stateless (no side effects).

- [ ] L-2.2 Implement `selectVariant(LegesTarief $tariff, Case $case): ?string`
  - Iterates LegesTarief.variants
  - For each variant: evaluates condities (JSON rules: spoedAanvraag, leeftijd, etc.)
  - Returns first matching variant's variantId, or null if no match
  - Condities evaluation is declarative (JSON rules engine, possibly inline PHP or delegated to a rule-evaluator)
  - **Acceptance**: Correctly selects "spoed" variant when spoedAanvraag = true; returns null when no variant matches; supports AND/OR logic in condities.

- [ ] L-2.3 Implement `applyDiscounts(LegesBerekening &$berekening, array $discounts): void`
  - Iterates all LegesKorting rules where tariefIds contains the calculated tariff
  - For each rule: evaluates condities (age, income, repeat-within-months, etc.)
  - If condition matches:
    - If kortingsType = "percentage": bedragExclBtw -= bedragExclBtw × kortingsWaarde / 100
    - If kortingsType = "vast_bedrag": bedragExclBtw -= kortingsWaarde
    - If kortingsType = "volledige_vrijstelling": bedragExclBtw = 0
    - Append to berekening.appliedKortingen array
  - Recalculates BTW and bedragInclBtw after all discounts
  - Updates berekeningsToelichting with discount details
  - **Acceptance**: Applies single discount correctly; applies multiple discounts (additive); "volledige_vrijstelling" results in bedrag = 0; audit trail includes all applied discounts with amounts.

- [ ] L-2.4 Implement external-data lookups in discount evaluation:
  - Age lookup: given zaak.aanvragerBsn, fetch geboortedatum from BRP (via OR connector or cached service)
  - Income lookup: for minima-vrijstelling, check gemeentelijke minima-registratie (if configured) or request inkomensverklaring from aanvrager
  - Repeat lookup: query for prior zaken by same aanvrager within N months
  - **Acceptance**: Age lookup returns geboortedatum or caches BRP data; minima-lookup returns status (in-register / pending-verification / denied); repeat-lookup returns count of prior zaken within timeframe.

- [ ] L-2.5 Implement Staffel rule evaluation (for "bouwsom", "oppervlakte" groundslags):
  - If LegesTarief.grondslag = "bouwsom": tariff defines staffel-table (e.g., "€0–50k: 2%, €50–100k: 2.5%, €100k+: 3%")
  - Look up zaak.bouwsom in staffel ranges, return applicable percentage
  - Multiply zaak.bouwsom × percentage to get bedrag
  - Same logic for "oppervlakte" grondslag (m² ranges)
  - **Acceptance**: Correctly returns 3% for bouwsom €250k in a "100k+: 3%" bracket; supports multiple brackets per tariff; staffel data is stored/configurable (per tariff or global).

---

## [procest] Event Listeners

### L-3. Implement LegesCalculationListener (S)

- [ ] L-3.1 Create `lib/Listener/LegesCalculationListener.php` implementing `\OCA\OpenRegister\Event\ZaakCreatedEvent`
  - On zaak creation:
    - Check caseType.legesTariefId is set; if not, skip
    - Call LegesCalculationService.calculateLeges(zaak)
    - Save returned LegesBerekening to procest-register
    - Update zaak.legesBerekening = ref
    - Emit LegesBerekningCalculatedEvent (for downstream listeners)
  - **Acceptance**: Listener fires on zaak-create; LegesBerekening is saved with correct values; zaak.legesBerekening ref is set; no exceptions for zaaktypes without legesTariefId.

- [ ] L-3.2 Register listener in `lib/Listener/ListenerRegistry.php` or service config
  - Ensure listener is called after zaak-create is persisted (not in transaction)
  - **Acceptance**: Listener is auto-discovered by OR event system; fires without registration errors.

---

### L-4. Implement LegesRestitutionListener (S)

- [ ] L-4.1 Create `lib/Listener/LegesRestitutionListener.php` implementing `\OCA\Procest\Event\ZaakStatusChangedEvent`
  - On zaak status → "ingetrokken":
    - Find LegesBerekening for this zaak; if none, skip
    - If LegesBerekening.status ∈ {gefactureerd, betaald}:
      - Determine restitutieStaffeling from caseType config
      - Calculate days between LegesBerekening.berekendeOp and today
      - Lookup restitutiePercentage in staffeling table
      - Calculate restitutieBedrag = bedragInclBtw × restitutiePercentage / 100
      - Create LegesRestitutie record
      - Call shillinq AR to create credit-note
      - Update LegesBerekening.status = "gerestitueerd"
      - Emit LegesRestitutionCreatedEvent
  - **Acceptance**: Listener fires correctly; calculates staffeled % correctly (14 days → 100%, 20 days → 75%, etc.); credit-note is sent to shillinq AR; LegesRestitutie record saved.

- [ ] L-4.2 Register listener in ListenerRegistry
  - **Acceptance**: Listener fires on zaak-status change without errors.

---

## [procest] Integration with shillinq AR

### L-5. Implement FacturingService (M)

- [ ] L-5.1 Create `lib/Service/FacturingService.php` with method `createInvoice(LegesBerekening $berekening): string` (returns factuurId)
  - Builds invoice request object:
    - debiteur: from zaak.aanvrager (BSN, naam, adres, plaats)
    - invoiceLines: from LegesBerekening (description, amount, VAT code)
    - glAccount: from LegesTarief.grootboekrekening
    - costCenter: from LegesTarief.kostendrager
    - zaakReference: from zaak.id
    - dueDate: today + 14 days (configurable)
  - Calls shillinq AR API: `POST https://[shillinq-host]/api/invoices` with Bearer token auth
  - Parses response: extracts factuurId
  - Returns factuurId
  - **Acceptance**: Correctly builds invoice payload; calls API without errors; parses factuurId from response; returns valid string.

- [ ] L-5.2 Implement error handling:
  - If debiteur fields missing (BSN, naam): raise exception with message "Kan factuur niet aanmaken: BSN / naam ontbreekt"
  - If shillinq API returns error (e.g., 4xx, 5xx): log error, raise exception with actionable message, set LegesBerekening.status = "error" (optional)
  - Retry logic (optional): 3 retries with exponential backoff for transient failures
  - **Acceptance**: Missing debiteur raises expected exception; API errors are logged and re-raised; no silent failures.

- [ ] L-5.3 Create `lib/Service/CreditNoteService.php` with method `createCreditNote(LegesRestitutie $restitutie): string` (returns creditfactuurId)
  - Builds credit-note request:
    - linkedInvoiceId: from LegesBerekening.factuurId
    - creditAmount: from LegesRestitutie.restitutieBedrag
    - reason: "Aanvraag ingetrokken" (or other restitutieReden text)
    - zaakReference: from zaak.id
  - Calls shillinq AR API: `POST https://[shillinq-host]/api/credit-notes`
  - Parses response: extracts creditfactuurId
  - Returns creditfactuurId
  - **Acceptance**: Correctly builds credit-note payload; calls API without errors; parses creditfactuurId from response.

---

## [procest] Admin UI

### L-6. Implement LegesTariefManagementPanel.vue (L)

- [ ] L-6.1 Create `src/components/admin/LegesTariefManagementPanel.vue` with tabs for:
  1. **Import**: file upload (XLSX/CSV), preview, validation, diff viewer, publish button
  2. **Version History**: table of LegesTariefTabel versions with dates, status, change counts
  3. **Tariff Editor**: inline editable table of LegesTarief rows (search, filter, add/delete)
  4. **Discount Rules**: table of LegesKorting rules with add/edit/delete buttons
  - **Acceptance**: Component renders without errors; all tabs are functional; no console errors.

- [ ] L-6.2 Implement "Import" tab:
  - File picker: accept XLSX, CSV, or JSON
  - Upload to backend endpoint: `POST /api/admin/leges/tariff-tables/import` (returns parsed preview)
  - Display preview table: tariefNummer, omschrijving, bedrag, grondslag, BTW%, GL, kostendrager
  - Validate: check all rows have required fields (grondslag, BTW, GL) — show error rows in red
  - Show diff vs. previous version (green: new, yellow: modified, red: deleted rows)
  - Buttons: "Review", "Publish (Vastgesteld)", "Cancel"
  - On "Publish": send `PUT /api/admin/leges/tariff-tables/{id}` with status = "vastgesteld"
  - **Acceptance**: File upload works; preview is accurate; validation shows errors correctly; diff is highlighting works; publish updates status in backend.

- [ ] L-6.3 Implement "Version History" tab:
  - Fetch and display list of LegesTariefTabel versions (with dates, status, counts)
  - Click on version → show diff or snapshot
  - **Acceptance**: List loads correctly; clicking shows snapshot/diff without errors.

- [ ] L-6.4 Implement "Tariff Editor" tab:
  - Fetch and display all LegesTarief rows for selected tabel (sorted by tariefNummer)
  - Inline edit: click cell → edit bedrag, grondslag, BTW, GL, kostendrager
  - Save on blur (or save-button)
  - Add row button: opens form to add new tariff
  - Delete row: soft-delete (mark vervallen), not hard-delete
  - **Acceptance**: Inline editing works; changes saved to backend; add/delete work; existing tariffs load correctly.

- [ ] L-6.5 Implement "Discount Rules" tab:
  - Fetch and display all LegesKorting rules (name, tariff-ids, type, conditions)
  - Add rule button: opens modal form with fields: naam, tariefIds (multi-select), kortingsType (dropdown), kortingsWaarde, condities (JSON editor)
  - Edit/delete buttons per row
  - **Acceptance**: List loads; add/edit/delete work without errors; conditions are editable as JSON.

---

## [procest] Zaak-Detail UI

### L-7. Implement LegesBerekningDetailPanel.vue (M)

- [ ] L-7.1 Create `src/components/zaak-detail/LegesBerekningDetailPanel.vue` to display on zaak-detail page
  - Display (read-only):
    - Heading: "Leges: €X.XXX (incl. VAT)"
    - Tariff: "2.3.1.1 Omgevingsvergunning bouwactiviteit"
    - Variant: "Spoed" (if applicable)
    - Bedrag ex-VAT, VAT (%), bedrag incl-VAT
    - Applied discounts list
    - Calculation date/time
    - Status badge: "Berekend" / "Gefactureerd" / "Betaald" / "Gerestitueerd"
  - Action buttons:
    - "Factureren" (if status = "berekend" and bedragInclBtw > 0)
    - "Restitutie" (if status = "gefactureerd" and zaak.status = "ingetrokken")
    - "Controleer berekening" → opens audit modal
  - **Acceptance**: Panel displays correctly; calculations shown are accurate; buttons are enabled/disabled correctly; no console errors.

- [ ] L-7.2 Implement "Factureren" button action:
  - On click: call `POST /api/leges/calculations/{id}/invoice` → creates invoice in shillinq AR
  - Update UI: show success toast "Factuur [id] verzonden"; refresh berekening status
  - On error: show error toast with message
  - **Acceptance**: Button calls API; factuur is created in shillinq; status updated to "gefactureerd"; user sees success/error feedback.

- [ ] L-7.3 Implement "Restitutie" button action:
  - On click: open modal form:
    - Staffeling options: show "% restitution based on days since factuur"
    - Approval flow (optional): if restitutiePercentage > 0 and restitutieReden ∈ {coulance, bezwaar_gegrond}, require belastingadviseur approval
    - Submit button → calls `POST /api/leges/calculations/{id}/restitution`
  - Update UI: show success toast; refresh status
  - **Acceptance**: Modal form displays; submission works; restitutie is created in shillinq; status updated to "gerestitueerd".

- [ ] L-7.4 Implement "Controleer berekening" link:
  - On click: opens LegesAuditDetailModal (see L-8 below)
  - **Acceptance**: Modal opens without errors; shows full audit trail.

---

### L-8. Implement LegesAuditDetailModal.vue (M)

- [ ] L-8.1 Create `src/components/zaak-detail/LegesAuditDetailModal.vue` with detailed audit information:
  - Tariff version used: link to snapshot
  - Tariff selected: number, description, grondslag, bedrag, VAT, GL
  - Variant selected (if any): name, conditions matched, adjustment applied
  - Zaak attributes used: bouwsom, leeftijd, herhaalaanvraag-status, etc.
  - Discount evaluation table: rule name, condition, result (✓/✗/⏳), bedrag reduction
  - BTW calculation: base × 21% = VAT
  - GL and cost-center
  - Calculation timestamp, calculated by (system/user)
  - Edit history (if manual corrections)
  - **Acceptance**: Modal displays all information clearly; no missing data; layout is readable.

- [ ] L-8.2 Implement "Export PDF" button:
  - Generates PDF with audit information (using jsPDF or server-side rendering)
  - Download as "Controleverslag_zaak_[id]_[date].pdf"
  - **Acceptance**: PDF generated correctly; downloads without errors; contains all audit info.

---

## [procest] REST Endpoints

### L-9. Implement Admin API Endpoints (S)

- [ ] L-9.1 Create `lib/Controller/LegesAdminController.php` with endpoints:
  - `GET /api/admin/leges/tariff-tables` → list all LegesTariefTabel with versions, status
  - `POST /api/admin/leges/tariff-tables/import` → upload file, parse, return preview (do NOT save yet)
  - `PUT /api/admin/leges/tariff-tables/{id}` → publish to "vastgesteld" or discard "concept"
  - `GET /api/admin/leges/tariff-tables/{id}/diff` → compare version with previous
  - `GET /api/admin/leges/discounts` → list all LegesKorting rules
  - `POST /api/admin/leges/discounts` → create new korting rule
  - `PUT /api/admin/leges/discounts/{id}` → edit rule
  - `DELETE /api/admin/leges/discounts/{id}` → soft-delete rule
  - All endpoints gated by role "belastingadviseur" (ABAC check)
  - **Acceptance**: All endpoints respond without errors; return correct HTTP status codes (200, 400, 403, 404); ABAC check works.

---

### L-10. Implement Zaak-Detail API Endpoints (S)

- [ ] L-10.1 Create endpoints in existing zaak controller or new LegesController:
  - `GET /api/leges/calculations/{id}` → fetch LegesBerekening with full audit detail
  - `POST /api/leges/calculations/{id}/invoice` → trigger FacturingService.createInvoice()
  - `POST /api/leges/calculations/{id}/restitution` → trigger LegesRestitutionListener or CreditNoteService.createCreditNote()
  - **Acceptance**: Endpoints return correct data; invoice/restitution calls work without errors.

---

## [procest] Tests

### L-11. Unit Tests for LegesCalculationService (M)

- [ ] L-11.1 Create `tests/Unit/Service/LegesCalculationServiceTest.php`:
  - ✓ Test tariff lookup by tariefNummer
  - ✓ Test bouwsom-based calculation (250k @ 3% = 7500 cents)
  - ✓ Test staffel-based calculation (bouwsom in range X → percentage Y)
  - ✓ Test VAT calculation (21%, 9%, 0%)
  - ✓ Test variant selection (spoedAanvraag = true → "spoed" variant)
  - ✓ Test discount application (single, multiple, percentage, fixed, full-exemption)
  - ✓ Test year-boundary tariff (zaak created 2026-12-20 uses 2026 tabel even if beschikking 2027-03-15)
  - **Acceptance**: All tests pass; code coverage ≥ 90% for LegesCalculationService.

- [ ] L-11.2 Create `tests/Unit/Service/FacturingServiceTest.php`:
  - ✓ Test invoice payload construction (debiteur, lines, GL, cost-center, VAT codes)
  - ✓ Test shillinq API call mocking (mock response with factuurId)
  - ✓ Test error handling (missing debiteur fields, API errors)
  - **Acceptance**: All tests pass; API mocking works; error cases are handled.

---

### L-12. Integration Tests (L)

- [ ] L-12.1 Create `tests/Feature/LegesCalculationFlowTest.php`:
  - ✓ Create zaak with leges-tarief → LegesBerekening is created automatically
  - ✓ LegesBerekening is persisted to register with correct values
  - ✓ Zaak.legesBerekening ref is set
  - ✓ Zaak status change to "ingetrokken" → LegesRestitutie is created
  - ✓ Credit-note is sent to shillinq AR
  - ✓ Staffeling is calculated correctly (14 days → 100%, 20 days → 75%)
  - **Acceptance**: All scenarios pass end-to-end; register state is correct; shillinq integration works (mocked).

- [ ] L-12.2 Create `tests/Feature/TariffManagementTest.php`:
  - ✓ Import tariff file (XLSX/CSV)
  - ✓ Publish tariff (status = "vastgesteld")
  - ✓ Multiple tariff versions chained correctly (v1.0 2026-01-01 to 06-30, v1.1 2026-07-01 to 12-31)
  - ✓ Cases use correct tariff based on peildatum
  - **Acceptance**: All scenarios pass; tariff chaining is correct; peildatum-rule enforced.

- [ ] L-12.3 Create `tests/Feature/DiscountApplicationTest.php`:
  - ✓ Age-based discount (65-plus): evaluated from BRP data
  - ✓ Income-based discount (minima): pending verification, then applied/denied
  - ✓ Repeat discount (herhaalaanvraag within 12 months): detected and applied
  - ✓ Multiple discounts applied additively
  - **Acceptance**: All discount scenarios work; external data lookups integrated; audit trail complete.

---

### L-13. UI Tests (M)

- [ ] L-13.1 Create E2E test suite (Cypress/Playwright):
  - ✓ Admin imports tariff file → sees preview, publishes
  - ✓ Zaak created → LegesBerekningDetailPanel displays correct amounts
  - ✓ Admin clicks "Factureren" → invoice created in shillinq (mocked API)
  - ✓ Admin clicks "Controleer berekening" → audit modal shows full details
  - ✓ Zaak status changed to "ingetrokken" → "Restitutie" button appears, clicking creates credit-note
  - **Acceptance**: All user workflows pass without errors; UI state is consistent; no console errors.

---

## [procest] Documentation

### L-14. Update API Documentation and CHANGELOG (S)

- [ ] L-14.1 Update `docs/api/leges.md` (create new file if needed):
  - Document all new endpoints (admin & zaak-detail)
  - Example requests/responses for invoice creation, restitution
  - Authorization requirements (belastingadviseur role)
  - **Acceptance**: Documentation is complete and accurate; examples work.

- [ ] L-14.2 Update `CHANGELOG.md`:
  - Add entry: "feat: Add leges-heffingen capability — automated tariff calculation, invoice creation, restitution processing"
  - List 6 new entities, 10 endpoints, 3 new Vue components
  - **Acceptance**: CHANGELOG updated.

---

## [procest] Deployment & Go-Live

### L-15. Schema Migrations & Database Setup (S)

- [ ] L-15.1 Verify OpenRegister schema registration:
  - procest_register.json is loaded by OR at startup
  - All 6 new schemas are registered without errors
  - Existing data is not affected (backward compatible)
  - **Acceptance**: `composer check:strict` passes; register loads without errors; no data loss.

- [ ] L-15.2 Configuration & Setup:
  - Configure shillinq-ar integration: API URL, authentication token (stored in .env or secure config)
  - Configure default restitution-staffeling per zaaktype (in admin UI or config file)
  - Configure tariff-import defaults (GL chart range, cost-center mapping, etc.)
  - **Acceptance**: Configuration is applied; endpoints can reach shillinq AR; admin can configure tariffs.

---

### L-16. UAT & Final Verification (M)

- [ ] L-16.1 Test with real data (or realistic test data):
  - Create 5 test zaaktypes (burgerzaken, omgevingsvergunning, APV-vergunning, etc.)
  - Link each to a tariff (legesTariefId)
  - Create test zaken, verify LegesBerekening calculated correctly
  - Create test invoices in shillinq AR (or mock)
  - Verify invoice amounts, GL, cost-center are correct
  - Test restitution flow (withdraw zaak, verify credit-note created)
  - Test discount application (65-plus, minima, herhaalaanvraag)
  - **Acceptance**: All test scenarios pass; calculations are correct; no data integrity issues.

- [ ] L-16.2 Performance testing (optional):
  - Load test: 1000 zaak-creates with leges calculation → verify response time < 2s
  - Bulk discount evaluation: 100 pending minima-checks with async verification → verify system handles concurrent lookups
  - **Acceptance**: Response times acceptable; no database locks or slowdowns.

- [ ] L-16.3 User acceptance testing with personas:
  - **Burgerzaken medewerker**: creates zaak, sees leges displayed, factureren button works
  - **Vergunningverlener**: creates complex omgevingsvergunning (bouwsom-based), verifies tariff calculation
  - **Financieel medewerker**: views admin panel, imports tariff, verifies GL/cost-center mapping
  - **Belastingadviseur**: manages discount rules, handles minima-verificatie flow, reviews audit trails
  - **Burger**: submits aanvraag, sees estimated leges, receives invoice
  - **Acceptance**: All personas can complete key workflows; no blockers or usability issues.

---

## [procest] Post-Launch Support

### L-17. Monitoring & Bug Fixes (Ongoing)

- [ ] L-17.1 Set up monitoring alerts:
  - LegesCalculationService failures → alert to operations team
  - shillinq AR API errors → alert (invoice creation failures)
  - High pending-minima-check queue → alert (async verification backlog)
  - **Acceptance**: Alerts configured; team can respond to failures.

- [ ] L-17.2 Known issues / follow-up:
  - (Optional) Dynamic staffeling editor in UI (currently stored per-tariff; could be parameterized)
  - (Optional) Bulk re-calculation tool (if tariff table is published retroactively, allow admin to recalculate prior zaken)
  - (Optional) Exportable tariff comparison (for gemeenteraad review before publication)

---

## Summary

**Total Estimate**: ~6–8 weeks for a small team (1 backend, 1 frontend, 1 QA).

**Key Milestones**:
1. **Week 1–2**: Schema & data model (L-1), LegesCalculationService (L-2), tests (L-11)
2. **Week 2–3**: Event listeners (L-3, L-4), shillinq integration (L-5)
3. **Week 3–4**: Admin UI (L-6), zaak-detail UI (L-7, L-8)
4. **Week 4–5**: API endpoints (L-9, L-10), E2E tests (L-13)
5. **Week 5–6**: UAT, documentation (L-14), deployment prep (L-15)
6. **Week 6+**: Go-live, monitoring, post-launch support (L-17)

**Success Criteria** (from proposal):
- ✓ All 6 entities in procest_register.json
- ✓ Zaak-create triggers LegesBerekening automatically
- ✓ Kortingen applied correctly (conditie-eval + external verification)
- ✓ Invoice created in shillinq AR with correct GL/cost-center/VAT
- ✓ Restitutie-creditfactuur on zaak-withdrawal with staffeling
- ✓ Jaargrens-zaken use historic tariff (peildatum rule)
- ✓ Audit trail complete (tariff version, variant, kortingen, amounts, who/when/why)
- ✓ QA: all 10 requirements tested per persona
- ✓ `openspec validate --strict leges-heffingen` exits 0
- ✓ All tests pass, no console errors, no security vulns
