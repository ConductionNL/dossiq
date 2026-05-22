# Tasks: termijnbewaking-dwangsom-engine

Implementation tasks for deadline monitoring engine, termijn pause/extension logic, ingebrekestelling validation, dwangsom calculation, financial integration, and reporting.

---

## 1. Core Data Model & Schema Registration

### Task 1: Create TermijnDefinitie, TermijnInstance, TermijnGebeurtenis Schemas
**Spec ref**: REQ-TERM-001, design section "Data Model"
**Files**: 
- `openspec/architecture/adr-000-data-model.md` (add 6 new schemas)
- Tests/seed: `lib/Settings/seed-termijn-definities.json`
**Acceptance criteria**:
- GIVEN admin accesses OpenRegister WHEN creates a TermijnDefinitie THEN system stores zaaktype, wettelijkeGrondslag, standaardDuurDagen, validFrom, validUntil
- TermijnInstance auto-created on zaak-creation with zaak reference, startDatum, einddatumBerekend
- TermijnGebeurtenis immutable audit trail with type enum, tijdstip, actor, grondslag, motivering

- [ ] Add TermijnDefinitie schema to adr-000-data-model.md with 6 properties (zaaktype, wettelijkeGrondslag, standaardDuurDagen, standaardDuurWeken, verlengingsRuimte, aantalVerlengingen, pauzeeVerlengingsDuren, afwijkendDwangsomRegime, validFrom, validUntil)
- [ ] Add TermijnInstance schema with status enum (lopend, gepauzeerd, verlengd, voltooid, overschreden, ingetrokken)
- [ ] Add TermijnGebeurtenis schema with type enum and immutable event structure
- [ ] Add Ingebrekestelling schema with ontvangstDatum, kanaal, gevalideerd, geldigheidStatus
- [ ] Add DwangsomBerekening schema with tariff tiers (€23, €35, €45) and plafond enforcement
- [ ] Add DwangsomUitbetaling schema with IBAN, bedrag, wettelijkeGrondslag, betaaldatumUiterlijk
- [ ] Verify all schemas follow adr-000 naming convention
- [ ] Create seed TermijnDefinities for Omgevingsvergunning, Wmo, Woo

### Task 2: OpenRegister Integration Setup
**Spec ref**: design "Integration Boundaries"
**Files**:
- `lib/Service/TermijnService.php`
- `lib/Service/ObjectServiceClient.php` (extend or create wrapper)
**Acceptance criteria**:
- GIVEN the termijn-engine starts WHEN it initializes THEN it connects to OpenRegister API with authentication
- All CRUD operations on termijn entities go through OpenRegister REST API
- Audit trail for all mutations is maintained by OpenRegister

- [ ] Create TermijnService class with OpenRegister client initialization
- [ ] Implement CRUD methods: createTermijnInstance(), getTermijnInstance(), updateTermijnInstance()
- [ ] Implement getTermijnDefinitie() with caching (definitions are rarely updated)
- [ ] Test OpenRegister connectivity and error handling (network failures, 401, 403, 404)
- [ ] Verify audit trails are auto-populated by OpenRegister on all mutations

---

## 2. Termijn Lifecycle Management (Pause, Resume, Extend)

### Task 3: Implement Pause Logic (AWB 4:5 / 4:15 Hersteltermijn)
**Spec ref**: REQ-TERM-002, design "PauseService"
**Files**:
- `lib/Service/PauseService.php`
- `lib/Controller/TermijnController.php` (add endpoint)
**Acceptance criteria**:
- GIVEN a hersteltermijn-verzoek is registered WHEN pause is applied THEN einddatumActueel extends, status=gepauzeerd, event recorded
- GIVEN aanvulling is received WHEN resume is triggered THEN pause-days are consumed proportionally, status=lopend, einddatumActueel recalculated
- GIVEN pause expires without response WHEN scan runs THEN alert issued, handler advised per AWB 4:5

- [ ] Implement `PauseService.registerPauze(termijnInstanceId, duurDagen, motivering, documentLink)` method
- [ ] Calculate einddatumActueel extension: original + unused pause days
- [ ] Record TermijnGebeurtenis with type="pauze", dagenImpact=+duurDagen
- [ ] Implement `resumeAfterPauze(termijnInstanceId, aanvullingDatum)` method
- [ ] Calculate consumed vs. unconsumed pause days
- [ ] Emit event "termijn-pause-resumed" with recalculated einddatumActueel
- [ ] Implement pause-expiry detection in daily scan (track pause-deadline in TermijnInstance)
- [ ] Generate pro-active alert to handler on pause expiry without response
- [ ] Test: pause extends deadline correctly, resume proportionally consumes days, expiry alerts fire

### Task 4: Implement Single Extension (AWB 4:14)
**Spec ref**: REQ-TERM-003
**Files**:
- `lib/Service/ExtensionService.php`
- `lib/Controller/TermijnController.php` (extend)
**Acceptance criteria**:
- GIVEN first extension request with motivering WHEN validated THEN extension approved, einddatumActueel updated, event recorded
- GIVEN second extension request WHEN validation runs THEN system rejects unless exceptional grondslag provided + supervisor approval

- [ ] Implement `ExtensionService.requestExtension(termijnInstanceId, motivering, newEinddatum, documentLink)` method
- [ ] Validate: motivering is non-empty, newEinddatum > current einddatumActueel, aantalVerlengingen < maxVerlengingen
- [ ] Update TermijnInstance: aantalVerlengingen++, einddatumActueel=newEinddatum
- [ ] Record TermijnGebeurtenis with type="verleng", motivering, dagenImpact=(newEinddatum - currentEinddatum)
- [ ] Emit notification trigger for verlengingsbrief to applicant
- [ ] Implement second-extension blocking with clear error message citing AWB 4:14 lid 3
- [ ] Implement override pathway: supervisor approval flow (separate endpoint, audit trail)
- [ ] Test: first extension succeeds, second extension blocked, override requires approval

---

## 3. Pro-Active Escalation Alerts

### Task 5: Implement Daily Termijn Scan Cronjob
**Spec ref**: REQ-TERM-004, design "Daily Cronjob"
**Files**:
- `lib/Job/DailyTermijnScanJob.php`
- `lib/Service/EscalationService.php`
- `lib/Controller/CronjobController.php` (expose endpoint)
**Acceptance criteria**:
- GIVEN termijn-scan runs daily WHEN thresholds are checked THEN 14d, 7d, 2d alerts are sent with correct escalation levels
- Overschrijding detection marks cases and emits event
- Dwangsom accrual runs for open DwangsomBerekening records

- [ ] Create DailyTermijnScanJob class with scheduled execution
- [ ] Query all active TermijnInstances (status != "voltooid" && "overschreden" && "ingetrokken")
- [ ] Calculate days-to-deadline for each; bucket into thresholds: 14d, 7d, 2d, 0 (overschreden)
- [ ] For each bucket, call EscalationService.notifyThreshold()
- [ ] For 14d threshold: notify handler via Nextcloud + email
- [ ] For 7d threshold: notify handler + teamleader with elevated priority
- [ ] For 2d threshold: notify handler + teamleader + afdelingsmanager with RED-FLAG priority
- [ ] For overschrijding: set TermijnInstance.status="overschreden", record event, emit "termijn-overschreden" event
- [ ] Test: scan correctly identifies approaching deadlines, escalation distribution is correct
- [ ] Verify: job runs daily at configured time (default 01:00 UTC); error handling if job fails

### Task 6: Implement Notification Escalation Matrix
**Spec ref**: REQ-TERM-004
**Files**:
- `lib/Service/EscalationService.php`
- `lib/Settings/escalation-matrix.json` (configuration)
**Acceptance criteria**:
- GIVEN case is 14 days to deadline WHEN scan runs THEN handler receives single notification (not duplicate)
- GIVEN case is 2 days to deadline WHEN scan runs THEN handler, teamleader, manager all notified; manager gets elevated priority
- GIVEN case is overschreden WHEN scan runs THEN all case-access users notified; case-detail-UI shows red warning

- [ ] Define escalation-matrix.json: threshold (14, 7, 2, 0=overschreden) × notification-targets (handler, teamleader, manager) × priority
- [ ] Implement `EscalationService.notifyThreshold(termijnInstance, thresholdDays)` method
- [ ] Fetch role assignments (handler, teamleader, manager) from case
- [ ] Generate notification message template per threshold (personalized: case-ID, deadline date, action-needed)
- [ ] Send via procest notification-router (Nextcloud notificatie + email)
- [ ] Track sent notifications in TermijnInstance.notificatiesVerstuurd to prevent duplicates on same threshold
- [ ] Test: correct recipients per threshold, templates include relevant case info, duplicates prevented

---

## 4. Ingebrekestelling & Validation

### Task 7: Implement Ingebrekestelling Registration and Overschrijding Validation
**Spec ref**: REQ-TERM-005
**Files**:
- `lib/Service/IngebrekestellingService.php`
- `lib/Controller/IngebrekestellingController.php`
**Acceptance criteria**:
- GIVEN overschreden TermijnInstance WHEN ingebrekestelling registered THEN gevalideerd=true, DwangsomBerekening created with grace-period start
- GIVEN premature ingebrekestelling WHEN registered THEN gevalideerd=false, no DwangsomBerekening, handler advised to re-register post-deadline

- [ ] Implement `IngebrekestellingService.registerIngebrekestelling(termijnInstanceId, ontvangstDatum, kanaal, documentLink)` method
- [ ] Validate: termijnInstance.status="overschreden" AND termijnInstance.einddatumActueel < ontvangstDatum
- [ ] If valid: set Ingebrekestelling.gevalideerd=true, geldigheidStatus="geldig"
- [ ] If invalid: set gevalideerd=false, geldigheidStatus="premaat", return error with advice
- [ ] On successful registration: mark Ingebrekestelling as "the relevant" (TermijnInstance.relevantIngbrekes = this Ingebrekestelling)
- [ ] Auto-create DwangsomBerekening with startDatum = ontvangstDatum + 14 days, status="lopend", huidigeDag=0
- [ ] Emit "ingebrekestelling-ontvangen" event to event-bus
- [ ] Send burger notification with grace-period end date and dwangsom-tariff transparency
- [ ] Test: valid registrations create DwangsomBerekening, premature registrations rejected, grace-period calculated correctly

### Task 8: Prevent Multiple Dwangsommen per Termijn
**Spec ref**: REQ-TERM-010 "Edge Cases"
**Files**:
- `lib/Service/IngebrekestellingService.php` (extend)
**Acceptance criteria**:
- GIVEN first ingebrekestelling registered WHEN second ingebrekestelling submitted THEN only first is marked "relevant", system prevents second DwangsomBerekening

- [ ] On registerIngebrekestelling(): check if TermijnInstance.relevantIngbrekes is already set
- [ ] If yes: register Ingebrekestelling but do NOT create DwangsomBerekening
- [ ] Return info message to handler: "Ingebrekestelling #2 ontvangen. Dwangsom-basis blijft de eerste ingebrekestelling van [date]."
- [ ] Test: multiple ingebrekestelling registrations, only first spawns DwangsomBerekening

---

## 5. Dwangsom Daily Calculation Engine

### Task 9: Implement DwangsomCalculationService with Tariff Tiers and Plafond
**Spec ref**: REQ-TERM-006
**Files**:
- `lib/Service/DwangsomCalculationService.php`
- Constants: `lib/Constant/DwangsomTariff.php` (€23, €35, €45, grace days, plafond)
**Acceptance criteria**:
- GIVEN DwangsomBerekening running WHEN daily calculation runs THEN cumulatievBedrag correctly applies tariff tiers per AWB 4:17
- GIVEN day 1–14: €23/day, day 15–28: €35/day, day 29+: €45/day
- GIVEN cumulativeBedrag reaches €1.442 THEN plafondBereikt=true, no further accrual

- [ ] Define constants: TARIFF_TIER_1_DAYS=14, TARIFF_TIER_1_RATE=23, TARIFF_TIER_2_DAYS=14, TARIFF_TIER_2_RATE=35, TARIFF_TIER_3_RATE=45, PLAFOND=1442
- [ ] Implement `DwangsomCalculationService.calculateDaily(dwangsomBerekeningId)` method
- [ ] Query DwangsomBerekening: startDatum (grace-period end), huidigeDag, cumulatievBedrag, plafondBereikt
- [ ] If plafondBereikt=true: return (no accrual, skip to next day)
- [ ] Else: determine current tariff tier based on huidigeDag; add tariff to cumulatievBedrag
- [ ] If cumulatievBedrag >= plafond: cap at plafond, set plafondBereikt=true
- [ ] Update huidigeDag++, dagtarief, cumulatievBedrag in DwangsomBerekening
- [ ] Test: tariff transitions at day 15 and day 29 correct, plafond enforcement, no overshoot

### Task 10: Integrate DwangsomCalculation into Daily Scan
**Spec ref**: design "Daily Cronjob"
**Files**:
- `lib/Job/DailyTermijnScanJob.php` (extend from Task 5)
**Acceptance criteria**:
- GIVEN daily scan runs WHEN DwangsomBerekening records exist THEN daily calculation is applied to all "lopend" records
- Projections are accurate for management reporting

- [ ] In DailyTermijnScanJob, after termijn-threshold checks, query all DwangsomBerekening with status="lopend"
- [ ] For each: call DwangsomCalculationService.calculateDaily()
- [ ] Emit "dwangsom-accrued" event with {zaakId, dailyIncrement, newCumulativeBedrag}
- [ ] Test: calculations apply correctly each day, events emitted, reporting reflects current state

### Task 11: Stop Dwangsom on Beschikking Registration
**Spec ref**: REQ-TERM-006-D, REQ-TERM-007-A
**Files**:
- `lib/Service/TermijnService.php` (extend with beschikking handler)
**Acceptance criteria**:
- GIVEN TermijnInstance reaches beschikking WHEN beschikkingDatum registered THEN DwangsomBerekening stops with status="gestopt-wegens-beschikking", definitievBedrag locked

- [ ] Create method `markTermijnCompleted(termijnInstanceId, beschikkingDatum, beschikkingDocumentLink)`
- [ ] Set TermijnInstance.status="voltooid", beschikkingDatum=registered date
- [ ] Query related DwangsomBerekening (via Ingebrekestelling); if running: stop with status="gestopt-wegens-beschikking", definitievBedrag=cumulatievBedrag
- [ ] Record TermijnGebeurtenis with type="voltooi"
- [ ] Call DwangsomUitbetalingService.prepareBetaling() (see Task 12)
- [ ] Test: beschikking registration stops accrual, final dwangsom locked

---

## 6. Financial System Integration (openconnector)

### Task 12: Implement DwangsomUitbetaling Preparation and Signal Emission
**Spec ref**: REQ-TERM-007
**Files**:
- `lib/Service/DwangsomUitbetalingService.php`
- `lib/Controller/DwangsomUitbetalingController.php`
**Acceptance criteria**:
- GIVEN DwangsomBerekening closes WHEN DwangsomUitbetalingService.prepareBetaling() called THEN DwangsomUitbetaling created with bedrag, IBAN, wettelijkeGrondslag, betaaldatumUiterlijk
- Structured payment-signal emitted to openconnector event-bus for ERP consumption

- [ ] Implement `DwangsomUitbetalingService.prepareBetaling(dwangsomBerekeningId)` method
- [ ] Fetch DwangsomBerekening.definitievBedrag, Ingebrekestelling.ontvangstDatum, zaak details
- [ ] Query case for burger contact info (rekeninghouderNaam, IBAN from aanvraag or contact record)
- [ ] Validate IBAN format; raise error if missing/invalid
- [ ] Create DwangsomUitbetaling with: bedrag, rekeninghouderNaam, iban, referentie="zaakId-ingebrekestelling-date", wettelijkeGrondslag="AWB 4:17 lid 2", betaaldatumUiterlijk=ingebrekestelling-date + 28 days
- [ ] Set status="voorbereid"
- [ ] Emit "dwangsom-payment-signal" event to openconnector with full metadata: {zaakId, dwangsomBedrag, rekeninghouderNaam, iban, referentie, wettelijkeGrondslag, betaaldeadline, caseLink}
- [ ] Log emission to audit trail
- [ ] Test: IBAN validation, event payload structure, correct deadline calculation

### Task 13: Implement openconnector Callback Handler (Payment Confirmation)
**Spec ref**: REQ-TERM-007-C
**Files**:
- `lib/Controller/OpenconnectorCallbackController.php` (new endpoint)
- `lib/Service/DwangsomUitbetalingService.php` (extend)
**Acceptance criteria**:
- GIVEN ERP processes payment WHEN callback arrives with {referentie, status, werkelijkeBetaaldatum, betalingsreferentie} THEN DwangsomUitbetaling updated, burger notified

- [ ] Create POST `/api/procest/openconnector/dwangsom-payment-callback` endpoint
- [ ] Validate callback signature (openconnector webhook authentication)
- [ ] Parse payload: referentie (lookup DwangsomUitbetaling), status, werkelijkeBetaaldatum, betalingsreferentie
- [ ] Update DwangsomUitbetaling: status=callback-status, werkelijkeBetaaldatum, betalingsreferentie
- [ ] Emit "dwangsom-betaald" event if status=betaald
- [ ] Send burger notification: "Dwangsom van €{bedrag} is op {werkelijkeBetaaldatum} naar uw rekening overgemaakt. Referentie: {betalingsreferentie}"
- [ ] Test: callback parsing, IBAN lookup, notification generation, error handling (referentie not found)

---

## 7. Burger Notifications

### Task 14: Implement Notification Templates & Burst Notification System
**Spec ref**: REQ-TERM-008
**Files**:
- `lib/Notification/TermijnNotificationTemplate.php`
- `lib/Service/NotificationService.php`
- `lib/Settings/notification-templates/` (Dutch message templates)
**Acceptance criteria**:
- GIVEN termijn events (receipt, extension, ingebrekestelling, payment) WHEN notification-trigger fired THEN burger receives clear, Dutch-language message with relevant deadline info
- All notifications include case reference, deadline, action-next (if applicable)

- [ ] Create notification template for "ontvangstbevestiging": zaak-ref, wettelijke termijn, calculated deadline, portal link
- [ ] Create template for "extension-notification": new deadline, copy of extension-letter (if available)
- [ ] Create template for "ingebrekestelling-receipt": confirmation date, grace-period-end-date, dwangsom-tariff transparency
- [ ] Create template for "dwangsom-payment-notification": bedrag, payment-date, payment-reference, confirmation message
- [ ] Implement `NotificationService.sendTermijnNotification(notificationType, termijnInstanceId, recipientUserId)` method
- [ ] Render template with case-specific data (zaak-ref, dates, amounts)
- [ ] Send via procest notification-router (Nextcloud notificatie + email + portal message)
- [ ] Log all sent notifications to audit trail
- [ ] Test: template rendering, multi-channel delivery, recipient resolution

### Task 15: Integrate Notifications into Termijn Lifecycle
**Spec ref**: REQ-TERM-001-B, REQ-TERM-002-A, REQ-TERM-003-A, REQ-TERM-005-A, REQ-TERM-008
**Files**: Various service files (extend each service to emit notifications)
**Acceptance criteria**:
- GIVEN zaak created WHEN TermijnInstance auto-created THEN ontvangstbevestiging sent to burger
- GIVEN extension registered WHEN event recorded THEN extension-notification sent
- GIVEN ingebrekestelling registered WHEN validated THEN ingebrekestelling-receipt notification sent
- GIVEN dwangsom paid WHEN callback confirms THEN payment-notification sent

- [ ] In TermijnService.createTermijnInstance(): emit "request-ontvangstbevestiging-notification" after successful creation
- [ ] In ExtensionService.requestExtension(): emit "request-extension-notification" after event recorded
- [ ] In IngebrekestellingService.registerIngebrekestelling(): emit "request-ingebrekestelling-notification" after validation succeeds
- [ ] In openconnector callback handler: emit "request-payment-notification" after DwangsomUitbetaling status updated
- [ ] Implement async notification queue (prevent blocking on SMTP failures)
- [ ] Test: notifications sent at correct lifecycle moments, async processing works

---

## 8. Management Reporting & Dashboards

### Task 16: Implement Quarterly KPI Report Generation
**Spec ref**: REQ-TERM-009-A
**Files**:
- `lib/Service/ReportingService.php`
- `lib/Controller/ReportingController.php`
- `lib/Settings/reports/` (report templates)
**Acceptance criteria**:
- GIVEN afdelingshoofd requests kwartaalrapport WHEN period specified THEN report generated with per-zaaktype breakdown: totaal-zaken, % within-termijn, avg duration, extensions, overschrijdingen, ingebrekestellingen, dwangsom-total

- [ ] Implement `ReportingService.generateQuarterlyReport(periode, afdeling=null)` method
- [ ] Query TermijnInstances created in periode; groupBy zaaktype
- [ ] For each zaaktype: calculate KPIs
  - [ ] totaal-zaken = count(TermijnInstances)
  - [ ] binnenTermijn-count = count where TermijnInstance.status="voltooid" OR "lopend" (not yet overschreden)
  - [ ] binnenTermijn-% = binnenTermijn-count / totaal × 100
  - [ ] gemiddeldeDuur = avg(beschikkingDatum - startDatum) for completed cases
  - [ ] aantalVerlengingen = sum(TermijnInstances where aantalVerlengingen > 0)
  - [ ] aantalOverschrijdingen = count(TermijnInstances where status="overschreden")
  - [ ] aantalIngebrekestellingen = count(Ingebrekestelling where geldigheidStatus="geldig")
  - [ ] dwangsom-total = sum(DwangsomBerekening.definitievBedrag) for closed calculations
- [ ] Format output as HTML table (for viewing) + CSV/JSON export
- [ ] Include metadata: report-generated-date, period, filtering criteria
- [ ] Test: KPI calculations correct, export formats valid

### Task 17: Implement Annual Dwangsom Audit Report
**Spec ref**: REQ-TERM-009-B
**Files**:
- `lib/Service/ReportingService.php` (extend)
- `lib/Controller/ReportingController.php` (extend)
**Acceptance criteria**:
- GIVEN accountant requests jaaroverzicht WHEN year specified THEN CSV/JSON generated with all dwangsommen: zaak-ref, zaaktype, ingebrekestelling-date, beschikking-date, bedrag, payment-date, payment-ref

- [ ] Implement `ReportingService.generateDwangsomAuditReport(jaar)` method
- [ ] Query all DwangsomUitbetaling records with werkelijkeBetaaldatum in specified year
- [ ] For each: fetch related DwangsomBerekening, Ingebrekestelling, zaak
- [ ] Extract: zaak.identifier, zaaktype, ingebrekestelling.ontvangstDatum, beschikkingDatum (from TermijnInstance), dwangsom.definitievBedrag, uitbetaling.werkelijkeBetaaldatum, uitbetaling.betalingsreferentie
- [ ] Validate all required fields populated (alerts if missing)
- [ ] Generate CSV with headers: Zaak-Referentie, Zaaktype, Ingebrekestelling-Datum, Beschikking-Datum, Dwangsom-Bedrag, Betaal-Datum, Betalings-Referentie
- [ ] Generate JSON export with same structure for ERP import
- [ ] Include summary statistics: total records, total amount, count by status
- [ ] Test: correct data extraction, export format validation, year filtering

### Task 18: Create Dashboard KPI Widget
**Spec ref**: design "Integration Boundaries"
**Files**:
- `lib/Service/DashboardService.php`
- `lib/Controller/DashboardController.php`
- Frontend: TBD (integration with procest-dashboard)
**Acceptance criteria**:
- GIVEN dashboard loads WHEN KPI widget rendered THEN displays: total-zaken, % within-termijn, avg duration, overschrijdingen, dwangsom-total (real-time aggregated)

- [ ] Implement `DashboardService.getTermijnKPI(filters={afdeling, zaaktype, periode})` method
- [ ] Query aggregated metrics from reporting service (cache results, expire hourly)
- [ ] Return: {totalZaken, withinTermijnPercent, avgDurationDays, overrunCount, dwangsomTotal, lastUpdated}
- [ ] Expose via REST endpoint: GET `/api/procest/dashboard/termijn-kpi`
- [ ] Test: KPI endpoint returns correct metrics, caching works

---

## 9. Bezwaar Handling (AWB 4:18)

### Task 19: Implement Bezwaar-Against-Dwangsom Registration & Resolution
**Spec ref**: REQ-TERM-010
**Files**:
- `lib/Service/DwangsomBezwaarService.php`
- `lib/Controller/DwangsomBezwaarController.php`
**Acceptance criteria**:
- GIVEN dwangsom calculated WHEN bezwaar filed THEN DwangsomBerekening frozen, DwangsomUitbetaling paused, payment suspended
- GIVEN bezwaar resolved WHEN new bedrag set THEN DwangsomBerekening updated, DwangsomUitbetaling resumed

- [ ] Implement `DwangsomBezwaarService.registerBezwaar(dwangsomBerekeningId, grondslag, motivering)` method
- [ ] Add bezwaar-event to DwangsomBerekening audit trail (or separate BezwaarRecord)
- [ ] Set DwangsomUitbetaling.status="on-hold-bezwaar"
- [ ] Emit "dwangsom-bezwaar-registered" event
- [ ] Send burger confirmation: "Uw bezwaarschrift is ontvangen. Betaling is opgeschort."
- [ ] Implement `resolveBezwaar(bezwaarRecordId, newBedrag, grondslag)` method
- [ ] Update DwangsomBerekening.definitievBedrag=newBedrag
- [ ] Update DwangsomUitbetaling.bedrag=newBedrag
- [ ] Set DwangsomUitbetaling.status="voorbereid" (re-initiate payment signal)
- [ ] Emit "dwangsom-bezwaar-resolved" event
- [ ] Send burger notification with revised amount
- [ ] Test: bezwaar registration, payment suspension, resolution with amount change

---

## 10. API Layer & REST Endpoints

### Task 20: Implement REST API Endpoints (Comprehensive)
**Spec ref**: design "API Design"
**Files**:
- `lib/Controller/TermijnController.php`
- `lib/Controller/IngebrekestellingController.php`
- `lib/Controller/DwangsomController.php`
- `lib/Controller/ReportingController.php`
**Acceptance criteria**:
- All endpoints documented and tested; input validation; error handling; permission checks

- [ ] TermijnController endpoints:
  - [ ] `POST /api/procest/termijn/instance` — Create TermijnInstance
  - [ ] `GET /api/procest/termijn/instance/{zaakId}` — Retrieve TermijnInstance
  - [ ] `POST /api/procest/termijn/instance/{zaakId}/pauze` — Register pause
  - [ ] `POST /api/procest/termijn/instance/{zaakId}/hervat` — Resume pause
  - [ ] `POST /api/procest/termijn/instance/{zaakId}/verleng` — Request extension
  - [ ] `POST /api/procest/termijn/instance/{zaakId}/voltooi` — Mark completed
- [ ] IngebrekestellingController endpoints:
  - [ ] `POST /api/procest/ingebrekestelling` — Register formal notification
  - [ ] `GET /api/procest/ingebrekestelling/{ingebrekestellingId}` — Retrieve record
- [ ] DwangsomController endpoints:
  - [ ] `GET /api/procest/dwangsom/{zaakId}` — Get current state
  - [ ] `POST /api/procest/dwangsom/{zaakId}/beschikking` — Register beschikking
  - [ ] `POST /api/procest/dwangsom/{zaakId}/bezwaar` — File bezwaar
  - [ ] `POST /api/procest/dwangsom/{zaakId}/bezwaar-heroverweging` — Resolve bezwaar
- [ ] ReportingController endpoints:
  - [ ] `GET /api/procest/termijn/dashboard` — Dashboard KPI
  - [ ] `GET /api/procest/termijn/kwartaalrapport` — Quarterly report
  - [ ] `GET /api/procest/termijn/jaarrekening-dwangsommen` — Annual audit report
- [ ] Input validation (required fields, format checks, range validation)
- [ ] Permission checks (admin for config, handler for case operations, accountant for reports)
- [ ] Error handling (400 bad request, 401 unauthorized, 403 forbidden, 404 not found, 409 conflict)
- [ ] Test all endpoints with curl/Postman; validate response formats

---

## 11. Testing & Quality Assurance

### Task 21: Implement Unit Tests for Core Services
**Spec ref**: All
**Files**:
- `tests/Unit/Service/TermijnServiceTest.php`
- `tests/Unit/Service/DwangsomCalculationServiceTest.php`
- `tests/Unit/Service/PauseServiceTest.php`
- `tests/Unit/Service/ExtensionServiceTest.php`
- `tests/Unit/Service/IngebrekestellingServiceTest.php`
**Acceptance criteria**:
- All core services have unit tests with >80% code coverage
- Tests cover happy path, edge cases, error conditions

- [ ] Create DwangsomCalculationServiceTest with tariff transition tests (day 14→15, day 28→29)
- [ ] Test plafond enforcement (no overshoot, final dwangsom locked)
- [ ] Test PauseService: pause extends deadline, resume consumes days proportionally
- [ ] Test ExtensionService: first extension succeeds, second extension blocked, override requires approval
- [ ] Test IngebrekestellingService: valid overschreden registration, premature registration rejected
- [ ] Test edge cases: multiple ingebrekestellingen (only first spawns DwangsomBerekening), zaak intrekking (stops accrual)
- [ ] Run tests via CI/CD; ensure all pass before merge

### Task 22: Implement Integration Tests (OpenRegister, Event-Bus)
**Spec ref**: design "Integration Boundaries"
**Files**:
- `tests/Integration/TermijnOpenRegisterTest.php`
- `tests/Integration/EventEmissionTest.php`
**Acceptance criteria**:
- Full workflow tested end-to-end: zaak-creation → TermijnInstance → pause → resume → extension → overschrijding → ingebrekestelling → dwangsom → payment

- [ ] Test zaak-creation hook triggers TermijnInstance creation in OpenRegister
- [ ] Test pause/resume with OpenRegister storage and retrieval
- [ ] Test daily scan with mocked time (simulate days passing, verify tariff transitions)
- [ ] Test event emission to event-bus (verify events are structured, catchable by consumers)
- [ ] Test openconnector callback integration (mock ERP callback, verify DwangsomUitbetaling updated)
- [ ] Run integration tests against test OpenRegister instance

### Task 23: Implement End-to-End Test Scenarios
**Spec ref**: REQ-TERM-001 through REQ-TERM-010
**Files**:
- `tests/Feature/TermijnWorkflowTest.php`
**Acceptance criteria**:
- Complete workflows tested: normal case, pause/resume case, extended case, overschrijding + dwangsom case, bezwaar case

- [ ] Test Scenario 1: Normal case (zaak created → no pause/extension → beschikking before deadline)
- [ ] Test Scenario 2: Pause case (incomplete aanvraag → hersteltermijn registered → aanvulling → resume)
- [ ] Test Scenario 3: Extension case (handler-initiated 1st extension → beschikking after extension)
- [ ] Test Scenario 4: Overschrijding + Dwangsom (TermijnInstance overschreden → ingebrekestelling registered → daily accrual → beschikking → payment signal)
- [ ] Test Scenario 5: Bezwaar (dwangsom registered → bezwaar filed → resolution with amount change)
- [ ] All scenarios verified: correct status transitions, event emissions, notifications sent, calculations accurate

---

## 12. Documentation & Admin Configuration

### Task 24: Create Admin Configuration UI for TermijnDefinities
**Spec ref**: design "Seed Data"
**Files**:
- `src/views/admin/TermijnDefinitiesTab.vue`
- `src/components/TermijnDefinitieEditor.vue`
**Acceptance criteria**:
- Admin can view, create, edit, and version TermijnDefinities
- Changes take effect only for new zaaktypen; existing zaaktypen retain original definitions

- [ ] Create admin tab listing all TermijnDefinities with zaaktype, wettelijkeGrondslag, duration, validity period
- [ ] Build editor form: zaaktype (dropdown from existing zaaktypen), wettelijkeGrondslag (text), durationDagen (number), verlengingsRuimte (number), afwijkendDwangsomRegime (optional)
- [ ] Implement versioning: on save, create new version with validFrom=today+1, mark prior version validUntil=today
- [ ] Test: create new definition, edit (creates version), verify new zaaktypen use latest version

### Task 25: Create Administrator Documentation
**Spec ref**: All tasks
**Files**:
- `docs/termijnbewaking-admin-guide.md`
- `docs/termijnbewaking-user-guide.md`
**Acceptance criteria**:
- Admin guide covers: configuration, daily scan setup, troubleshooting, reporting
- User guide explains deadlines, pause/extension, ingebrekestelling, dwangsom from handler perspective

- [ ] Admin guide: How to configure TermijnDefinities, how to run daily scan (cronjob setup), how to troubleshoot missed alerts
- [ ] User guide: Explanation of AWB deadlines, pause grounds, extension request, how to register ingebrekestelling, where to find dwangsom reports
- [ ] Both guides in Dutch with examples, screenshots (if applicable)

