# Specs: leges-heffingen voor zaaktype-gestuurde aanvragen

## Overview

Detailed requirements for automated tariff calculation, variant selection, discount application, invoice creation in shillinq AR, restitution processing, and audit trails. All calculations are deterministic, deterministic, and auditable.

---

## REQ-LEGES-001: Tariefverordening importeren uit raadsbesluit

**Purpose**: Administrator can import and version new tariff tables, with change detection and review workflow.

### REQ-LEGES-001-A: Upload and Parse Tariff File

GIVEN a municipality has a published "Legesverordening 2026" decision in decidesk with tariff table in XLSX or CSV format (columns: tariefNummer, omschrijving, bedrag, grondslag, eenheid, btwTarief, grootboekrekening, kostendrager)

WHEN a belastingadviseur clicks "Import new tariff table" in the admin UI and selects the XLSX file

THEN system:
- Parses the file row-by-row
- Validates each row has: tariefNummer (required), omschrijving (required), bedrag (required for vast/staffel, null for formule), grondslag (required), eenheid (required), btwTarief ∈ {0, 9, 21}, grootboekrekening (required, GL chart), kostendrager (required)
- Creates LegesTariefTabel with status = "concept", versie = max(previous versie) + 1
- Creates LegesTarief rows for each parsed row
- Shows validation result: "✓ 847 tariffs parsed, 0 errors"
- If validation fails: shows error report with row numbers and messages

### REQ-LEGES-001-B: Diff and Version History

GIVEN system has previous LegesTariefTabel v1.0 (2025-12-31) with 845 tariffs

WHEN new tabel v1.1 (2026-01-01) is imported with 847 tariffs (2 added, 1 modified, some unchanged)

THEN system displays diff viewer showing:
- NEW rows (green highlight): "1.1.3 Uittreksel huwelijk" (800 cents)
- MODIFIED rows (yellow): "2.3.1.1" bedrag changed from 7300 to 7500 cents
- DELETED rows (red): tariff "3.2.1" removed entirely (marked vervallen in system)
- UNCHANGED rows: not highlighted

### REQ-LEGES-001-C: Status Workflow and Notification

GIVEN imported tabel is in "concept" status

WHEN belastingadviseur clicks "Publish (Vastgesteld)" button

THEN system:
- Sets status = "vastgesteld", vastgesteldOp = today
- Closes previous tabel with geldigTotEnMet = yesterday (if there was one with same range)
- New tabel geldigVanaf is set to the specified date (e.g., 2026-01-01)
- Sends notification to financial team: "Tariff table v1.1 published, effective 2026-01-01"
- Cases created from now on use new tabel

WHEN belastingadviseur discards a concept tabel before publishing

THEN system marks it status = "vervallen" and removes from active use

---

## REQ-LEGES-002: Automatische tariefberekening op zaak-aanmaak

**Purpose**: Fees calculation is automatic, triggered by zaak creation, deterministic, and auditable.

### REQ-LEGES-002-A: Automatic Calculation on Zaak Create

GIVEN:
- A zaaktype "Omgevingsvergunning bouwactiviteit" is configured with legesTariefId = "2.3.1.1"
- A valid LegesTariefTabel for today (geldigVanaf ≤ today ≤ geldigTotEnMet)
- LegesTarief 2.3.1.1 has grondslag = "bouwsom", bedrag = null (template), eenheid = "%", btwTarief = 21

WHEN a zaak is created with attributes: bouwsom = 250000 (in eurocents = €2,500)

THEN system:
- Fires LegesCalculationListener on ZaakCreatedEvent
- Determines tariff is 2.3.1.1, grondslag = "bouwsom"
- Evaluates staffel-rules (if any) or fixed percentage:
  - If "3% of construction sum" is defined: €2,500 × 3% = €75 (75 eurocents? or €75 = 7500 cents?)
  - **Clarification**: all amounts stored in eurocents (€1 = 100 cents), so €75 = 7500 eurocents
- Calculates bedragExclBtw = 7500 eurocents = €75
- Calculates btwBedrag = 7500 × 21% / 100 = 1575 eurocents = €15.75
- Calculates bedragInclBtw = 7500 + 1575 = 9075 eurocents = €90.75
- Creates LegesBerekening object:
  ```
  zaakId: zaak-2026-0042
  tariefTabelId: tabel-2026-1
  tariefId: 2.3.1.1
  variantId: null
  appliedKortingen: []
  bedragExclBtw: 7500
  btwBedrag: 1575
  bedragInclBtw: 9075
  berekendeOp: 2026-05-22T14:32:00Z
  berekendDoor: "system"
  berekeningsToelichting: "Bouwsom €75,00 × 3% = €2,25; geen kortingen van toepassing"
  status: "berekend"
  ```
- Saves LegesBerekening to procest-register
- Sets zaak.legesBerekening = ref
- Emits LegesBerekningCalculatedEvent for downstream listeners (notifications, dashboards)

### REQ-LEGES-002-B: Non-Fees Zaaktypes Skip Calculation

GIVEN a zaaktype "Interne memo" is NOT configured with legesTariefId

WHEN zaak is created

THEN LegesCalculationListener skips (no event, no calculation) and zaak.legesBerekening = null

### REQ-LEGES-002-C: Missing Tabel Blocks Calculation

GIVEN a zaaktype "Omgevingsvergunning" is configured with legesTariefId = "2.3.1.1"

WHEN zaak is created but NO valid LegesTariefTabel exists for today (all tabels have geldigTotEnMet < today or geldigVanaf > today)

THEN system:
- Does NOT create LegesBerekening
- Marks zaak with status-flag: "leges-pending" or similar
- Creates system notification: "Zaak [id] cannot be processed for fees: no active tariff table. Configure one and re-calculate manually."
- Admin can manually trigger re-calculation once tabel is published

---

## REQ-LEGES-003: Variant-selectie op basis van zaakattributen

**Purpose**: Automated variant selection based on zaak properties and conditional rules.

### REQ-LEGES-003-A: Conditional Variant Selection

GIVEN:
- LegesTarief 2.4.1 "Rijbewijs aanvraag" has two variants:
  - Variant A (regular): bedrag = 4875 eurocents
  - Variant B (spoed): bedragOpslag = 2000 eurocents, condities = { spoedAanvraag: true }
- A zaak is created with zaak.spoedAanvraag = true

WHEN LegesCalculationService.calculateLeges() runs

THEN system:
- Evaluates LegesVariant[].condities for tariff 2.4.1
- Variant B's conditie { spoedAanvraag: true } matches zaak.spoedAanvraag = true
- Selects Variant B: bedrag = 4875 + 2000 = 6875 eurocents
- Stores in LegesBerekening.variantId = "B", bedragExclBtw = 6875
- Includes in berekeningsToelichting: "Variant B (spoed) geselecteerd omdat spoedAanvraag = true"

### REQ-LEGES-003-B: Variant Logging and Audit

GIVEN LegesBerekening with variantId selected

WHEN zaak-detail page is displayed or audit-log is queried

THEN system shows:
- Selected variant name and ID
- The condition that matched
- The bedrag adjustment applied
- Audit trail: "2026-05-22 14:32 system: Variant B selected (spoed=true)"

### REQ-LEGES-003-C: Multiple Variant Conditions (OR Logic)

GIVEN a LegesVariant with condities = { spoedAanvraag: true, prioriteitBehoud: true } (OR logic: if either is true, select)

WHEN zaak has spoedAanvraag = false but prioriteitBehoud = true

THEN variant is selected (OR: "spoedAanvraag OR prioriteitBehoud" = true)

---

## REQ-LEGES-004: Kortingen en vrijstellingen automatisch toepassen

**Purpose**: Discounts and exemptions are applied automatically based on rules, with full audit.

### REQ-LEGES-004-A: Discount Rule Matching

GIVEN:
- LegesKorting "65-plus vrijstelling rijbewijs verlenging" with:
  - tariefIds = ["2.4.1.1", "2.4.1.2"]
  - kortingsType = "volledige_vrijstelling"
  - condities = { leeftijd: { min: 65 } }
- A zaak for rijbewijs (tariff 2.4.1.1) with aanvrager age 67 (from BRP geboortedatum)

WHEN LegesCalculationService.calculateLeges() runs

THEN system:
- Iterates all LegesKortingen where tariefIds contains 2.4.1.1
- For "65-plus vrijstelling": evaluates condities.leeftijd.min = 65
- Zaak aanvrager is 67 years old → condition matches ✓
- Apply korting: kortingsType = "volledige_vrijstelling" → bedragExclBtw = 0 (full exemption)
- Stores in LegesBerekening.appliedKortingen:
  ```
  [{ korting-id: "65-plus-vrijstelling", bedrag: 4875, type: "volledige_vrijstelling" }]
  ```
- Result: LegesBerekening.bedragInclBtw = 0
- berekeningsToelichting includes: "Korting: 65-plus vrijstelling rijbewijs verlenging (€48,75)"

### REQ-LEGES-004-B: Multiple Discounts (Additive)

GIVEN two applicable discounts:
- "65-plus vrijstelling": €48.75 off
- "Herhaalaanvraag-korting" (5% reduction): €2.44 off
- Total bedrag before discounts: €97.50

WHEN both conditions match

THEN system:
- Applies both: €97.50 - €48.75 - €2.44 = €46.31
- Stores both in appliedKortingen array (with IDs and amounts)
- berekeningsToelichting lists both reductions

### REQ-LEGES-004-C: Percentage Discount Application

GIVEN:
- LegesKorting "Herhaalaanvraag-korting 12 maanden" with:
  - kortingsType = "percentage"
  - kortingsWaarde = 5 (5%)
  - condities = { herhaalaanvraagBinnen: 12 }
- Previous zaak for same aanvrager created 6 months ago

WHEN current zaak is created with same aanvrager

THEN system:
- Checks: is there a previous zaak by same aanvrager within 12 months? Yes, 6 months ago
- Apply 5% discount: bedragExclBtw = 7500, discount = 7500 × 5% = 375 eurocents
- bedragExclBtw after discount = 7500 - 375 = 7125 eurocents
- Stores in appliedKortingen: { type: "percentage", kortingsWaarde: 5, bedrag: 375 }

### REQ-LEGES-004-D: Exemption Does Not Create Invoice

GIVEN LegesBerekening with appliedKortingen resulting in bedragInclBtw = 0 (volledig-vrijstelling)

WHEN zaak-behandelaar clicks "Factureren"

THEN system:
- Does NOT send invoice request to shillinq AR
- Creates a note in zaak-activity log: "Leges €0,00 (volledige vrijstelling 65-plus)"
- No factuurId is generated
- LegesBerekening.status remains "berekend" (not "gefactureerd")

---

## REQ-LEGES-005: Factuur creëren in shillinq accounts-receivable

**Purpose**: Invoice creation is automated, auditable, and correctly booked.

### REQ-LEGES-005-A: Invoice Creation API Call

GIVEN:
- A LegesBerekening with status = "berekend", bedragInclBtw = 9075 eurocents
- Zaak with aanvrager details (naam, adres, BSN)
- shillinq-installatie configured and accessible

WHEN zaak-behandelaar clicks "Factureren" button

THEN system:
- Calls shillinq AR API: `POST /api/invoices` with:
  ```json
  {
    "debiteur": {
      "bsn": "123456789",
      "naam": "J. de Vries",
      "adres": "Kerkstraat 42",
      "plaats": "Heffingen"
    },
    "invoiceLines": [
      {
        "description": "Omgevingsvergunning bouwactiviteit (tarief 2.3.1.1)",
        "amount": 9075,
        "vatCode": "21%",
        "vatAmount": 1575
      }
    ],
    "glAccount": "6200",
    "costCenter": "vergunningen",
    "zaakReference": "2026-0042",
    "dueDate": "2026-06-05",
    "currency": "EUR"
  }
  ```
- shillinq AR responds with factuurId (e.g., "F2026-00547")
- Updates LegesBerekening:
  - factuurId = "F2026-00547"
  - status = "gefactureerd"
  - berekendeOp (audit timestamp) is recorded
- Zaak-activity log: "Factuur F2026-00547 aangemaakt, verschuldigd op 2026-06-05"
- User receives notification: "Factuur F2026-00547 verzonden naar debiteur"

### REQ-LEGES-005-B: Missing Debiteur Handling

GIVEN a zaak with aanvrager details but missing BSN or Name

WHEN factuur-creation is attempted

THEN system:
- Validates that BSN and naam are present (required for debiteur creation)
- If missing: raises actionable error "Kan factuur niet aanmaken: BSN / naam ontbreekt. Controleer aanvraaggegevens."
- Does NOT create LegesBerekening or send invoice request
- User must complete missing fields before retry

### REQ-LEGES-005-C: Automatic Factuur on Configured Delay

GIVEN system is configured with "auto-factuur delay: 7 days"

WHEN a LegesBerekening is created (status = "berekend")

THEN after 7 days:
- Background job runs
- Sends invoice to shillinq AR automatically
- Sets factuurId and status = "gefactureerd"
- User receives notification (no manual action needed)

---

## REQ-LEGES-006: Restitutie bij ingetrokken aanvraag

**Purpose**: When a case is withdrawn, restitution is calculated using staffeled percentages and processed automatically.

### REQ-LEGES-006-A: Staffeled Restitution Calculation

GIVEN:
- A gefactureerde LegesBerekening (bedragInclBtw = 9075 eurocents, factuurId = "F2026-00547", factured on 2026-05-22)
- Gemeente restitution staffeling rules:
  - 0–14 days: 100% restitution
  - 15–30 days: 75% restitution
  - 31+ days: 0% restitution
- Zaak is marked "ingetrokken" on 2026-06-05 (14 days after factuur)

WHEN LegesRestitutionListener fires (zaak-status changed to "ingetrokken")

THEN system:
- Calculates days between factuur (2026-05-22) and withdrawal date (2026-06-05) = 14 days
- Looks up staffeling: 14 days → 100% restitution (still within 0–14 day window, inclusive)
- Calculates restitutieBedrag = 9075 × 100% = 9075 eurocents
- Creates LegesRestitutie:
  ```
  berekeningId: ref
  restitutieReden: "aanvraag_ingetrokken"
  restitutiePercentage: 100
  restitutieBedrag: 9075
  ```
- Calls shillinq AR: `POST /api/credit-notes` with:
  ```json
  {
    "linkedInvoiceId": "F2026-00547",
    "creditAmount": 9075,
    "reason": "Aanvraag ingetrokken",
    "zaakReference": "2026-0042"
  }
  ```
- Receives creditfactuurId (e.g., "CF2026-00132")
- Updates LegesRestitutie.creditfactuurId = "CF2026-00132"
- Updates LegesBerekening.status = "gerestitueerd"
- Notifies burger: "Restitutie van €90,75 akkoord; creditfactuur CF2026-00132"

### REQ-LEGES-006-B: Manual Restitution Approval (Bezwaar Gegrond)

GIVEN:
- A bezwaar-case on zaak 2026-0042 has been declared "gegrond" (appeal upheld)
- besluit-date: 2026-08-01 (70 days after factuur 2026-05-22)
- Gemeente staffeling for "bezwaar_gegrond": 0% restitution after decision (no refund if >60 days)

WHEN financieel-medewerker initiates restitution for "bezwaar gegrond" reason

THEN system:
- Calculates restitutiePercentage = 0% (beyond 60-day window)
- Creates LegesRestitutie with status = "concept" (pending approval)
- Shows UI: "Restitutie 0% = €0,00. Motivering: bezwaar gegrond na 70 dagen (geen restitutie na 60 dagen per staffeling)"
- Sends for approval to belastingadviseur: "Restitutie-besluit voor goedkeuring"
- Once approved: generates credit-note in shillinq AR and finalizes

### REQ-LEGES-006-C: Partial Restitution (Mid-Range)

GIVEN staffeling: 0–14 days 100%, 15–30 days 75%, 31+ days 0%

WHEN zaak is withdrawn on day 20 (between 15–30)

THEN restitutiePercentage = 75%
AND restitutieBedrag = 9075 × 75% = 6806 eurocents (rounding: 6806.25 → 6806)
AND credit-note is created for €68.06

---

## REQ-LEGES-007: Historisch correcte tariefkeuze bij jaargrens-zaken

**Purpose**: Cases spanning year boundaries use the tariff table valid on the submission date.

### REQ-LEGES-007-A: Peildatum (Submission Date) Rule

GIVEN:
- Zaak is created on 2026-12-20 (within 2026 tariff table)
- 2026 tariff table is valid 2026-01-01 to 2026-12-31
- 2027 tariff table is valid 2027-01-01 to 2027-12-31
- Tariff 2.3.1.1 "Omgevingsvergunning":
  - 2026 tabel: 3% of construction sum
  - 2027 tabel: 3.5% of construction sum (raised by gemeenteraad)
- Zaak is actually completed (beschikking given) on 2027-03-15

WHEN zaak is created on 2026-12-20 (submission date)

THEN system:
- Snapshot zaak.peildatum = 2026-12-20
- Loads LegesTariefTabel where geldigVanaf ≤ 2026-12-20 ≤ geldigTotEnMet (= 2026 tabel)
- Stores tariefTabelId = "tabel-2026" in LegesBerekening
- Calculation uses 2026 tariff rates (3%, not 3.5%)

WHEN zaak is completed on 2027-03-15

THEN system DOES NOT recalculate with 2027 rates (immutable historical tariff)

### REQ-LEGES-007-B: Explicit Recalculation (Audit Trail)

GIVEN a LegesBerekening calculated with 2026 rates

WHEN belastingadviseur explicitly requests "Herberekening met 2027 tarief" in the UI (for administrative reason)

THEN system:
- Creates a new LegesBerekening (does NOT overwrite)
- Indicates in notes: "Herbere

ekend met 2027 tarief op verzoek belastingadviseur, reden: [motivering]"
- Stores both old and new berekening in audit trail
- Uses new amount for future factuurstatus changes; old amount remains in history

---

## REQ-LEGES-008: Meerdere tariefwijzigingen per jaar ondersteunen

**Purpose**: Municipalities can update tariff tables multiple times per year with automatic chaining.

### REQ-LEGES-008-A: Multiple Versions in One Year

GIVEN:
- 2026 tariff v1.0 published 2026-01-01, valid 2026-01-01 to 2026-12-31
- Gemeente raises tariffs on 2026-07-01 due to inflation

WHEN belastingadviseur imports new tabel v1.1 with geldigVanaf = 2026-07-01

THEN system:
- Automatically closes v1.0 with geldigTotEnMet = 2026-06-30
- Creates v1.1 with geldigVanaf = 2026-07-01, geldigTotEnMet = 2026-12-31
- Cases submitted 2026-01-01 to 2026-06-30 use v1.0 tariffs
- Cases submitted 2026-07-01 onwards use v1.1 tariffs
- No manual intervention needed

### REQ-LEGES-008-B: Chain of Versions Visible

GIVEN multiple versions published in 2026

WHEN admin views "Version History" page

THEN system displays table:
```
Version | Effective Date | Status | Tariff Count | Changes
1.0     | 2026-01-01 to 2026-06-30 | vervallen | 845 | —
1.1     | 2026-07-01 to 2026-12-31 | vastgesteld | 847 | +2 tariffs, 5 modified
```

---

## REQ-LEGES-009: Audit-trail per berekening

**Purpose**: Every leges calculation is fully auditable for compliance and controller review.

### REQ-LEGES-009-A: Complete Audit Record

GIVEN a LegesBerekening

WHEN controller clicks "Controleer berekening" button

THEN system displays audit-detail panel with:
- **Tariff Version Used**: "Legesverordening 2026 v1.0" (link to snapshot)
- **Tariff Selected**: "2.3.1.1 Omgevingsvergunning bouwactiviteit"
  - Grondslag: "bouwsom"
  - Bedrag: "3% van bouwsom"
  - BTW: "21%"
  - GL: "6200"
  - Cost center: "vergunningen"
- **Variant Selected**: "Spoed" (if applicable)
  - Condition matched: spoedAanvraag = true
  - Bedrag adjustment: +€20,00
- **Zaak Attributes Used in Calculation**:
  - bouwsom: €250.000
  - spoedAanvraag: true
  - leeftijd aanvrager: 67 (from BRP, last sync 2026-05-22 14:15)
  - herhaalaanvraag within 12 months: nee
- **Discount Evaluation Table**:
  | Korting | Condition | Result | Bedrag |
  | 65-plus vrijstelling | leeftijd ≥ 65 (BRP: DOB 1959-03-15) | ✓ MATCHED | €48,75 |
  | Minima-vrijstelling | inkomen ≤ bijstandsnorm | ⏳ PENDING | — |
- **Amounts**:
  - Basis: €75,00
  - Less: 65-plus €48,75
  - Subtotal: €26,25 (ex-VAT)
  - VAT (21%): €5,51
  - Total: €31,76
- **Calculation Timestamp**: 2026-05-22 14:32:00
- **Calculated By**: "system" (automatic on zaak-create)
- **Edit History**: (if manual corrections applied, list all with who/when/why)

### REQ-LEGES-009-B: Export Audit for Accountant

GIVEN a LegesBerekening

WHEN accountant requests "Exporteer controleverslag zaak 2026-0042" (from audit panel)

THEN system generates PDF with:
- Zaak identifier and title
- All audit-detail information (as above)
- Calculation formula in plain Dutch
- External data sources referenced (BRP leeftijd, minima-register status)
- Calculation date and responsible role
- Signed by system (immutable proof)

---

## REQ-LEGES-010: Inkomensafhankelijke minima-vrijstelling met BRP/inkomensregister-check

**Purpose**: Income-dependent exemptions integrate with external data sources (BRP, income register) with async verification.

### REQ-LEGES-010-A: Pending Income Verification

GIVEN:
- LegesKorting "Minima-vrijstelling uittreksel BRP" with:
  - kortingsType = "volledige_vrijstelling"
  - condities = { huishoudinkomen: { max: "bijstandsnorm" } }
  - verificatieBron = "gemeentelijke-minima-registratie" or "inkomensverklaring"
- Zaak for uittreksel BRP by aanvrager who states they are "minima"

WHEN LegesCalculationService runs and evaluates minima-discount condition

THEN system:
- Checks: is bijstandsnorm verification available for this aanvrager?
- If NOT found: sets LegesBerekening.status = "pending-minima-check"
- Does NOT apply discount yet; holds the berekening in pending state
- Shows UI message: "Leges-berekening wacht op inkomensverificatie. Stuur aanvraagformulier inkomensverklaring naar aanvrager."
- Sends task to zaak-behandelaar: "Vraag inkomensverklaring op van aanvrager [naam] voor minima-vrijstelling"

### REQ-LEGES-010-B: Async Verification and Auto-Finalization

GIVEN LegesBerekening in "pending-minima-check" state

WHEN:
- Aanvrager submits inkomensverklaring (document upload)
- OR gemeentelijke minima-registratie lookup returns a result (positive: "in register", negative: "not in register")

THEN system:
- If verification = "positive" (qualifies for minima):
  - Applies volledig-vrijstelling discount
  - Sets status = "berekend" (no further fee)
  - bedragInclBtw = 0
  - Notifies aanvrager: "U bent geregistreerd als minima. Leges: €0,00 (vrijgesteld)."
- If verification = "negative" (does not qualify):
  - Does NOT apply discount
  - Sets status = "berekend"
  - Uses normal bedrag (e.g., €31,76)
  - Notifies: "Uw inkomensgegevens kwalificeren niet voor minima-vrijstelling. Leges: €31,76 verschuldigd."

### REQ-LEGES-010-C: Manual Override with Audit

GIVEN LegesBerekening in "pending-minima-check" state

WHEN belastingadviseur manually applies or denies the minima-vrijstelling (overrides verification)

THEN system:
- Creates manual override record in audit trail: "2026-05-24 10:15 belastingadviseur: minima-vrijstelling toegepast (override, reden: administratieve correctie)"
- Updates LegesBerekening.appliedKortingen and bedrag
- Stores motivering in notes

---

## Data Validation Rules

### LegesTariefTabel

- `naam`: required, non-empty
- `geldigVanaf`: required, ISO 8601 date
- `geldigTotEnMet`: required, ≥ geldigVanaf, ISO 8601 date
- `vastgesteldDoor`: optional, but recommended (reference to decidesk raadsbesluit)
- `status`: enum ∈ {concept, vastgesteld, vervallen}
- Constraint: only one "vastgesteld" tabel per date-range per gemeente

### LegesTarief

- `tariefNummer`: required, format "X.Y.Z..." (hierarchical), unique per tabel
- `omschrijving`: required, non-empty
- `bedrag`: required for grondslag ∈ {vast, oppervlakte, bouwsom, staffel}, null for grondslag = "formule"
- `grondslag`: enum ∈ {vast, oppervlakte, bouwsom, staffel, formule}
- `eenheid`: enum ∈ {per_stuk, per_m2, per_uur, percentage}
- `btwTarief`: integer ∈ {0, 9, 21}
- `grootboekrekening`: required, 4-digit GL code
- `kostendrager`: required, valid cost-center name

### LegesBerekening

- `zaakId`: required, FK to zaak
- `tariefTabelId`: required, FK to LegesTariefTabel
- `bedragExclBtw`, `btwBedrag`, `bedragInclBtw`: non-negative integers (eurocents)
- `status`: enum ∈ {berekend, gefactureerd, betaald, gerestitueerd, kwijtgescholden}
- Invariant: `bedragInclBtw = bedragExclBtw + btwBedrag` (server-side validation on all updates)
- Immutable: once status = "gefactureerd", amounts cannot be changed (only manual override creates new record)

### LegesRestitutie

- `berekeningId`: required, FK to LegesBerekening
- `restitutieReden`: enum ∈ {aanvraag_ingetrokken, dubbel_betaald, coulance, bezwaar_gegrond}
- `restitutiePercentage`: integer 0–100
- `restitutieBedrag`: non-negative integer (eurocents)
- Constraint: restitutieBedrag ≤ linked LegesBerekening.bedragInclBtw × 100% (cannot refund more than paid)

