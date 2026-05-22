# Design: leges-heffingen voor zaaktype-gestuurde aanvragen

## Context

### Current State: Manual Leges Calculation

Today, procest zaak-medewerkers:

```
Zaak aangemaakt (bv. omgevingsvergunning, bouwsom €250.000)
  ↓
Medewerker opent Excel-tabel "Legestarieven 2026"
  ↓
Zoekt handmatig: "Omgevingsvergunning, bouwactiviteit, 3% van bouwsom"
  ↓
Berekent handmatig: €250.000 × 3% = €7.500
  ↓
Spreidt handmatig uit over Excel: kolommen BTW%, groot-boekrekening
  ↓
Stuurt handmatig data naar boekhouden / shillinq AR
  ↓
Risico's: verkeerde tarief, vergeten kortingen, geen audit-trail
```

### Desired State: Automated Leges Calculation

After leges-heffingen:

```
Zaak aangemaakt (bv. omgevingsvergunning, bouwsom €250.000)
  ↓
procest-engine:
  1. laadt LegesTariefTabel voor 2026
  2. zoekt tariefnummer "2.3.1.1 Omgevingsvergunning bouwactiviteit"
  3. past bouwsom-staffel toe: €250.000 × 3% = €7.500
  4. evalueert kortingsregels (65-plus? minima? herhaalaanvraag?)
  5. maakt LegesBerekening aan
  6. stuurt factuur-request naar shillinq AR
  ↓
Zaak-detailpagina toont:
  - "Leges: €7.500 (berekend op 2026-05-22)"
  - "Tarief: 2.3.1.1 Omgevingsvergunning bouwactiviteit, 3% van bouwsom"
  - "BTW: €1.575 (21%)"
  - "Factuur: F2026-00547 (openstaand)"
  ↓
Audit-trail: compleet, traceerbaar, geen handwerk
```

---

## System Architecture

### Data Entities (New in ADR-000)

```
LegesTariefTabel [versionable per gemeente/jaar]
  ├─ versie: 1.0 (auto-increment per update)
  ├─ naam: "Legesverordening 2026"
  ├─ geldigVanaf: 2026-01-01
  ├─ geldigTotEnMet: 2026-12-31
  ├─ vastgesteldDoor: "raadsbesluit-2025-11-15" (ref naar decidesk)
  ├─ status: "vastgesteld" (concept, vastgesteld, vervallen)
  └─ LegesTarief[] (hiërarchisch genummerd)
      ├─ tariefNummer: "1.1.1" (Titel I, section 1, item 1)
      ├─ omschrijving: "Uittreksel BRP, eenmalig"
      ├─ bedrag: 800 (eurocenten)
      ├─ grondslag: "vast" (vast, oppervlakte, bouwsom, staffel, formule)
      ├─ eenheid: "per_stuk"
      ├─ btwTarief: 0 (0/9/21)
      ├─ grootboekrekening: "6100" (GL chart)
      ├─ kostendrager: "burgerzaken"
      └─ LegesVariant[] (e.g., "regulier" vs. "spoed")
          ├─ variantNaam: "Spoed"
          ├─ bedragOpslag: 2000 (bedrag of % opslag)
          └─ condities: { spoedAanvraag: true }

LegesKorting [standaard kortingsregels, gemeente-config]
  ├─ naam: "65-plus vrijstelling rijbewijs verlenging"
  ├─ tariefIds: ["2.4.1.1", "2.4.1.2"] (welke tarieven)
  ├─ kortingsType: "volledige_vrijstelling" (%, vast bedrag, of 0%)
  ├─ condities: { leeftijd: { min: 65 } }
  ├─ wettelijkeGrondslag: "Gemeentewet art. 229"
  └─ geldigVanaf/TotEnMet: date range

LegesBerekening [per zaak, immutable na "gefactureerd"]
  ├─ zaakId: ref naar zaak
  ├─ tariefTabelId: ref naar LegesTariefTabel (peildatum!)
  ├─ tariefId: "2.3.1.1"
  ├─ variantId: null / "spoed" (quale variant selected)
  ├─ appliedKortingen: [{ korting-id: "korting-65", bedrag: 4875 }]
  ├─ bedragExclBtw: 7500
  ├─ btwBedrag: 1575
  ├─ bedragInclBtw: 9075
  ├─ berekendeOp: 2026-05-22T14:32:00Z
  ├─ berekendDoor: "system" / "user-id"
  ├─ berekeningsToelichting: "Bouwsom €250.000 × 3% = €7.500; geen kortingen van toepassing"
  ├─ factuurId: "F2026-00547" (shillinq ref, set na facturering)
  └─ status: "berekend" → "gefactureerd" → "betaald" / "gerestitueerd"

LegesRestitutie [per teruggave, decision-audit trail]
  ├─ berekeningId: ref naar LegesBerekening
  ├─ restitutieReden: "aanvraag_ingetrokken" (ingetrokken, dubbel, coulance, bezwaar)
  ├─ restitutiePercentage: 75 (% teruggave o.b.v. fase/tijd)
  ├─ restitutieBedrag: 6806 (berekend: 9075 × 75%)
  ├─ creditfactuurId: "CF2026-00132" (shillinq credit-note)
  ├─ besluitNemerId: "medewerker-id"
  └─ besluitDatum: 2026-05-24
```

### Calculation Flow: Zaak-Aanmaak

```
User creates zaak (type "Omgevingsvergunning bouwactiviteit")
  ↓
procest.zaak.create event
  ↓
LegesCalculationListener (OR listener on zaak create)
  ├─ Read caseType → leges-tarief config? (if not, skip)
  ├─ Fetch LegesTariefTabel for today (geldigVanaf ≤ today ≤ geldigTotEnMet)
  ├─ Fetch LegesTarief for this tariff number
  ├─ Check zaak-attributes (bouwsom = €250.000)
  ├─ Determine variant (spoedAanvraag: true → "spoed" variant)
  ├─ Calculate bedrag = 250.000 × 3% = 7.500 (+ opslag spoed = +2.000 = 9.500?)
  │   (depends on variant-rule; spec clarifies this)
  ├─ Check & apply kortingen:
  │   ├─ foreach LegesKorting where tariefIds contains this tariff:
  │   │   ├─ evaluate condities (leeftijd, inkomen, herhaalaanvraag)
  │   │   ├─ if all true: apply (bedrag -= korting-value)
  │   ├─ result: appliedKortingen = [korting-id, bedrag]
  ├─ Calculate BTW (based on tabelBtwTarief: 21% → 7.500 × 21% = 1.575)
  ├─ Create LegesBerekening:
  │   {zaakId, tariefTabelId, tariefId, variantId, appliedKortingen, bedragExclBtw: 7500, btwBedrag: 1575, bedragInclBtw: 9075, status: "berekend"}
  ├─ Save to procest-register
  └─ zaak.legesBerekening = ref → LegesBerekening
  ↓
UI displays zaak-detail with "Leges: €9.075 (incl. BTW)"
  ↓
User clicks "Factureren"
  ├─ FacturingService.createInvoice(legesBerekening)
  ├─ Call shillinq AR API:
  │   POST /api/invoices with:
  │   {
  │     debiteur: {BSN, NAW},
  │     invoiceLines: [{ description: "Tarief 2.3.1.1", amount: 9075, vatCode: "21%" }],
  │     glAccount: "6100",
  │     costCenter: "burgerzaken",
  │     zaakReference: zaak-id,
  │     dueDate: today + 14 days
  │   }
  ├─ Receive factuurId back
  ├─ Update LegesBerekening.factuurId = factuurId, status = "gefactureerd"
  └─ Notify zaak-assignee: "Factuur [factuurId] verzonden"
```

### Calculation Flow: Restitutie (Zaak Ingetrokken)

```
User marks zaak as "ingetrokken" (withdrawn)
  ↓
LegesRestitutionListener (on zaak-status = ingetrokken)
  ├─ Find LegesBerekening (status = "gefactureerd" or "betaald")
  ├─ Determine restitutieStaffeling (gemeente-config per zaaktype)
  │   e.g. "ingetrokken": { 0-14days: 100%, 14-30days: 75%, 30+days: 0% }
  ├─ Calculate days since facturering
  ├─ restitutiePercentage = lookup in staffeling
  ├─ restitutieBedrag = LegesBerekening.bedragInclBtw × restitutiePercentage
  ├─ Create LegesRestitutie record
  ├─ Call shillinq AR:
  │   POST /api/credit-notes with:
  │   {
  │     linkedInvoiceId: LegesBerekening.factuurId,
  │     creditAmount: restitutieBedrag,
  │     reason: "Aanvraag ingetrokken",
  │     zaakReference: zaak-id
  │   }
  ├─ Receive creditNoteId
  ├─ Update LegesRestitutie.creditfactuurId = creditNoteId
  ├─ Update LegesBerekening.status = "gerestitueerd"
  └─ Notify burger: "Restitutie van €X.XXX akkoord; creditfactuur CF2026-XXXXX"
```

---

## File-by-File Implementation Plan

### 1. lib/Settings/procest_register.json — ADD 6 new entity schemas

Add schemas for:
- `legesTariefTabel` (versionable container)
- `legesTarief` (individual tariff line)
- `legesVariant` (sub-tariff per variant)
- `legesKorting` (discount/exemption rule)
- `legesBerekening` (concrete calculation per zaak)
- `legesRestitutie` (restitution record)

Each schema includes:
- Full property definitions with types, required flags, descriptions
- Relations to zaak, caseType, decision (where applicable)
- `x-openregister-authorization` blocks (who can read/write/delete)

Update `caseType` schema:
- Add optional `legesTariefId` property (reference to tariff if applicable)

Update `case` schema:
- Add optional `legesBerekening` property (reference to LegesBerekening)
- Add optional `legesRestitutie` property (reference to LegesRestitutie, if any)

---

### 2. lib/Service/LegesCalculationService.php — NEW

**Responsibility**: Single source-of-truth for tariff calculation logic.

**Public methods**:
- `calculateLeges(Case $case, LegesTariefTabel $tabel): LegesBerekening`
  - Load tariff, evaluate attributes, select variant, apply discounts, compute amounts
- `applyDiscounts(LegesBerekening $berekening, array $discounts): void`
  - Mutate berekening.appliedKortingen and bedrag fields
- `computeBtwAmount(int $bedragExclBtw, int $btwTariff): int`
  - Helper: compute VAT amount

**Calculation engine**:
- Variant selection: reads `zaak.properties` (bouwsom, oppervlakte, spoedAanvraag, etc.)
  and evaluates `LegesVariant.condities` (JSON rules) to pick correct variant
- Staffel evaluation: if `grondslag: "staffel"`, reads zaak-attribute and looks up
  value in tariff staffel-table (e.g., "€0–€50k → 2%", "€50–€100k → 2.5%", etc.)
- Discount application: reads `LegesKorting.condities`, evaluates each (age, income, repeat-within)
  and applies bedrag reduction or volledig-vrijstelling
- BTW calculation: based on `LegesTarief.btwTarief` (0/9/21)

---

### 3. lib/Listener/LegesCalculationListener.php — NEW

**Responsibility**: Automatically trigger leges calculation when zaak is created.

**Event**: `\OCA\Procest\Event\ZaakCreatedEvent`
- Check: does caseType have legesTariefId configured?
- If no: skip (not all case types have fees)
- If yes:
  - Fetch legesTariefTabel (peildatum = today)
  - Call LegesCalculationService.calculateLeges()
  - Save LegesBerekening to register
  - Update zaak.legesBerekening = ref
  - Emit event `LegesBerekningCalculatedEvent` for downstream listeners (e.g., notifications)

---

### 4. lib/Listener/LegesRestitutionListener.php — NEW

**Responsibility**: Automatically create restitution when zaak is withdrawn/closed.

**Event**: `\OCA\Procest\Event\ZaakStatusChangedEvent`
- Check: new status = "ingetrokken" or "withdrawn"?
- If yes:
  - Fetch LegesBerekening (if any)
  - If status = "gefactureerd" or "betaald":
    - Determine staffeling (gemeente config, zaaktype-specific)
    - Calculate restitutie% and amount
    - Create LegesRestitutie record
    - Call shillinq AR to create credit note
    - Update LegesBerekening.status = "gerestitueerd"
    - Emit event `LegesRestitutionCreatedEvent`

---

### 5. src/store/legesModule.js — NEW (Frontend State)

Vuex module for leges UI state:
- `tariffTables: {}` — loaded LegesTariefTabel versions
- `selectedTable: id` — currently selected tabel for viewing
- `discounts: []` — available LegesKorting rules
- `calculations: []` — history of LegesBerekening per zaak
- `mutations`:
  - loadTariffTables(tables)
  - selectTable(id)
  - updateCalculation(zaakId, berekening)
  - selectDiscounts(discounts)

---

### 6. src/components/LegesTariefManagementPanel.vue — NEW

**UI for tariff management** (admin view):

**Sections**:
1. **Import legesverordening**:
   - File picker: upload XLSX/CSV from decidesk or local
   - Parse & preview (show columns: tariefNummer, omschrijving, bedrag, BTW%, GL)
   - Validation: check all rows have grondslag + BTW + GL
   - Diff viewer: highlight changed/new/removed rows vs. previous version
   - Buttons: "Review", "Publish (vastgesteld)", "Cancel"
   - On publish: create LegesTariefTabel with status "vastgesteld"

2. **Version history**:
   - Table: date, status, tariff count, diff summary
   - Click → view snapshot / compare

3. **Tariff editor** (inline):
   - Table: show all LegesTarief rows (searchable, filterable)
   - Edit inline: bedrag, grondslag, BTW, GL, kostendrager
   - Add new tariff
   - Delete (soft-delete, mark as vervallen)

4. **Discount rules management**:
   - Table: name, tariff-ids, type (%, bedrag, vrijstelling), conditions
   - Add new rule
   - Edit conditions (age range, income threshold, etc.)

---

### 7. src/components/LegesBerekningDetailPanel.vue — NEW

**Display on zaak-detail page** (read-only audit view):

**Content**:
- Heading: "Leges: €X.XXX (incl. BTW)"
- Tariff: "2.3.1.1 Omgevingsvergunning bouwactiviteit"
- Variant: "Spoed" (if applicable)
- Bedrag ex-BTW: €7.500
- BTW (21%): €1.575
- Bedrag incl-BTW: €9.075
- Applied discounts:
  - "65-plus vrijstelling: -€4.875" (if any)
- Calculation date: 2026-05-22 14:32
- Calculation basis: "Bouwsom: €250.000 × 3% = €7.500"
- Status: "Berekend" / "Gefactureerd" (link to shillinq invoice) / "Betaald" / "Gerestitueerd"

**Action buttons**:
- "Factureren" (if status = "berekend")
- "Restitutie" (if status = "gefactureerd" and zaak = "ingetrokken")
- "Controleer berekening" (opens audit detail)

---

### 8. src/components/LegesAuditDetailModal.vue — NEW

**Deep dive audit view** (for controllers/accountants):

**Shows**:
- LegesTariefTabel version used (with link to full tabel snapshot)
- LegesTarief selected (tariefNummer, omschrijving, grondslag, bedrag template)
- Zaak attributes used in calculation:
  - bouwsom: €250.000
  - spoedAanvraag: true
  - leeftijd aanvrager: (from BRP) 67 years
  - herhaalaanvraag within 12 months: nee
- Variant selected & why: "Spoed variant selected because zaak.spoedAanvraag = true"
- Discount evaluation table:
  - Rule name | Condition | Result | Bedrag reduction
  - "65-plus vrijstelling" | age ≥ 65 (from BRP: DOB 1959-03-15) | ✓ MATCHED | €4.875
  - "Minima-vrijstelling" | income ≤ bijstandsnorm (external check pending) | ⏳ PENDING | —
- BTW: "21% standard rate per zaaktype 'omgevingsvergunning'"
- GL: "6100 Zaakbehandeling"
- Cost center: "Vergunningen"
- Calculation timestamp & who initiated: "2026-05-22 14:32 by system (automatic on zaak-create)"
- Edit history (if manual corrections applied later)

---

### 9. lib/Controller/LegesAdminController.php — NEW

**REST endpoints for admin**:

- `GET /api/leges/tariff-tables` → list all LegesTariefTabel with versions, status
- `POST /api/leges/tariff-tables/import` → receive file, parse, create new version in "concept" status
- `PUT /api/leges/tariff-tables/{id}` → publish to "vastgesteld" or discard concept
- `GET /api/leges/tariff-tables/{id}/diff` → compare with previous version
- `GET /api/leges/discounts` → list all LegesKorting rules
- `POST /api/leges/discounts` → create new korting rule
- `PUT /api/leges/discounts/{id}` → edit
- `DELETE /api/leges/discounts/{id}` → soft-delete

**Authorization**:
- All endpoints gated by role "belastingadviseur" (configured via ABAC-policy-engine)

---

### 10. tests/Unit/Service/LegesCalculationServiceTest.php — NEW

**Unit tests** for calculation engine:

- ✓ Tariff lookup by tariefNummer and zaak attributes
- ✓ Variant selection (spoedAanvraag = true → "spoed" variant)
- ✓ Staffel evaluation (bouwsom in range X → percentage Y)
- ✓ Discount application (single, multiple, all-or-nothing)
- ✓ BTW calculation (0%, 9%, 21%)
- ✓ Year-boundary tariff selection (zaak created 2026-12-20, calculated with 2026 tabel even if beschikking 2027)

**Integration tests** (Feature tests):

- ✓ Zaak-create triggers LegesBerekening automatically
- ✓ LegesBerekening saved to register with audit trail
- ✓ Zaak status change to "ingetrokken" triggers LegesRestitutie
- ✓ Restitution % correctly staffeled by days since facturering
- ✓ shillinq AR integration: factuur created with correct GL/cost-center/BTW

---

## Seed Data (3–5 per entity, Dutch values)

### LegesTariefTabel

```json
{
  "naam": "Legesverordening 2026 gemeente Heffingen",
  "geldigVanaf": "2026-01-01",
  "geldigTotEnMet": "2026-12-31",
  "vastgesteldDoor": "raadsbesluit-2025-11-15",
  "status": "vastgesteld",
  "versie": 1
}
```

### LegesTarief (sample rows)

```json
[
  {
    "tariefNummer": "1.1.1",
    "omschrijving": "Uittreksel geboorte",
    "bedrag": 800,
    "grondslag": "vast",
    "eenheid": "per_stuk",
    "btwTarief": 0,
    "grootboekrekening": "6100",
    "kostendrager": "burgerzaken"
  },
  {
    "tariefNummer": "2.3.1.1",
    "omschrijving": "Omgevingsvergunning bouwactiviteit",
    "bedrag": null,
    "grondslag": "bouwsom",
    "eenheid": "percentage",
    "btwTarief": 21,
    "grootboekrekening": "6200",
    "kostendrager": "vergunningen"
  }
]
```

### LegesVariant

```json
{
  "tariefId": "2.3.1.1",
  "variantNaam": "Spoed",
  "bedragOpslag": 2000,
  "condities": { "spoedAanvraag": true }
}
```

### LegesKorting

```json
[
  {
    "naam": "65-plus vrijstelling rijbewijs verlenging",
    "tariefIds": ["2.4.1.1", "2.4.1.2"],
    "kortingsType": "volledige_vrijstelling",
    "condities": { "leeftijd": { "min": 65 } },
    "wettelijkeGrondslag": "Gemeentewet art. 229"
  },
  {
    "naam": "Minima-vrijstelling uittreksel BRP",
    "tariefIds": ["1.1.1", "1.1.2"],
    "kortingsType": "volledige_vrijstelling",
    "condities": { "huishoudinkomen": { "max": "bijstandsnorm" } },
    "wettelijkeGrondslag": "Sociaalwet"
  }
]
```

### LegesBerekening

```json
{
  "zaakId": "zaak-2026-0042",
  "tariefTabelId": "legestabel-2026-1",
  "tariefId": "2.3.1.1",
  "variantId": "spoed",
  "appliedKortingen": [],
  "bedragExclBtw": 7500,
  "btwBedrag": 1575,
  "bedragInclBtw": 9075,
  "berekendeOp": "2026-05-22T14:32:00Z",
  "berekendDoor": "system",
  "berekeningsToelichting": "Bouwsom €250.000 × 3% = €7.500; geen kortingen van toepassing",
  "factuurId": null,
  "status": "berekend"
}
```

---

## Data Model Updates (ADR-000)

**New entities** (6 total):

1. **legesTariefTabel** — versionable tariff table per municipality per year
2. **legesTarief** — individual tariff line within table
3. **legesVariant** — variant (e.g., "spoed") with conditional bedrag adjustment
4. **legesKorting** — discount/exemption rule with conditions and waiver grounds
5. **legesBerekening** — concrete calculation instance per case
6. **legesRestitutie** — restitution record with staffeling and decision audit trail

**Modified entities**:

- **caseType**: add optional `legesTariefId` (reference)
- **case**: add optional `legesBerekening` (reference), `legesRestitutie` (reference)

---

## Integration Points

### shillinq accounts-receivable

- `POST /api/invoices` → create factuur with GL, cost-center, VAT
- `POST /api/credit-notes` → create creditfactuur (restitution)
- Payment-sync webhook: procest listens for payment-received, updates LegesBerekening.status

### decidesk

- Tariff import: procest admin downloads decision "Legesverordening 2026" from decidesk as XLSX/CSV
- Manual process (future automation: decidesk webhook → auto-import)

### openregister-abac-policy-engine

- Tariff management & restitution approval gated by role/group membership

### pipelinq / openconnector

- Income verification for minima-exemption (via gemeentelijke minima-registratie or BRP inkomensverklaring)
