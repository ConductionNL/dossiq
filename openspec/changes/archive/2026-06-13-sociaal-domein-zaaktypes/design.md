# Design: sociaal-domein-zaaktypes

## Domain framing — the social domain as a distinct zaak universe

Dutch municipalities manage three quasi-independent operational domains:

| Domain | Case examples | Processing character | Privacy level | Coordination |
|---|---|---|---|---|
| **VTH** (Omgeving/Toezicht/Handhaving) | Omgevingsvergunning, Toezicht (inspecties), Handhavingsactie | Public interests, property law, spatial planning | Standard PII (name, address) | Single-organization decision chain |
| **Sociaal domein** | WMO-onderzoek, Jeugdwet-melding, Bijstandsaanvraag, re-integratie | Individual vulnerability, medical/behavioral/financial assessment, family intervention | **Special categories (AVG art 9)**: medical, family, financial, sometimes ethnicity/religion | Multi-professional (wijkteam + externe partners: zorg, onderwijs, justitie) |
| **Bezwaar-beroep** | Bezwaarschrift, hoorzitting, beroep | Procedural rights, legal challenge | Standard PII + decision-data (public interest) | Administrative court sequence |

Procest today handles VTH + bezwaar-beroep well. This change extends procest to handle the **social domain** as a first-class citizen, with all its constraints:

1. **Special-category data is mandatory, not accidental** — a WMO-zaak *by definition* contains medical assessment; a Jeugdwet-zaak *by definition* contains family situation & behavioral data. The system must enforce that classification is recorded *at creation*, not left to chance.

2. **Access is narrower than RBAC alone** — a "wijkteam-zuid" staff member can see all WMO-zaken assigned to their team. A "wijkteam-noord" staff member cannot see a WMO-zaak in wijkteam-zuid, even if they have the generic "view case content" role. The zaak's team membership is a data-driven guard, not a role.

3. **Data-sharing requires consent** — external parties (zorgaanbieder, CJG, GGD, huisarts) may need to know about a case (e.g., the jeugdzorg provider needs the family plan to execute its intervention), but the family must explicitly consent. If consent is not recorded, data must be anonymized on export.

4. **Retention is statutory, not discretionary** — WMO cases must be kept 15 years; Jeugdwet 20 years; Participatiewet 10 years. The system must calculate the destroy-date at case closure and auto-generate voorstel-s when the date approaches.

5. **Every read-access is audited** — because the data is sensitive, every time a staff member opens a case, the system logs who opened what fields and when (for later FG-audit or subject-access-request fulfillment).

## Zaaktype architecture — three statutory pillars

```
┌───────────────────────────────────────────────────────────────┐
│ procest case-management (existing)                             │
│ case, caseType, statusType, role, decision, document,         │
│ workflow-engine, parafering-actions, bezwaar-beroep           │
└──────────────────────┬──────────────────────────────────────┘
                       │ all 3 sociaal-domein zaaktypes consume
         ┌─────────────┼──────────────┐
         ▼             ▼              ▼
    ┌─────────┐  ┌──────────┐  ┌────────────┐
    │ WmoZaak │  │Jeugdwet- │  │Participatie│
    │ + Indic │  │Zaak      │  │wetZaak    │
    │ atellin │  │+ Gezins- │  │+ReIntegra-│
    │ g       │  │plan+MDO  │  │tieTraject │
    └────┬────┘  └────┬─────┘  └─────┬──────┘
         │            │              │
         └─────┬──────┴──────────────┘
               ▼
         ┌───────────────────────┐
         │ AVG & Consent         │
         │ Infrastructure        │
         │ (mandatory on all 3)  │
         │ - avgClassificatie    │
         │ - toestemming         │
         │ - audit-logging       │
         │ - access-guards       │
         └───────────────────────┘
               │
               ▼
         ┌───────────────────────┐
         │ Cross-app integration │
         │ - openconnector       │
         │   (iWMO/iJW, externe) │
         │ - openregister        │
         │   (retention, RBAC)   │
         │ - docudesk            │
         │   (beschikking docs)  │
         │ - launchpad              │
         │   (wijkteam dashboard)│
         └───────────────────────┘
```

## Entity relationship overview

Each zaaktype is backed by an OpenRegister schema. The flow:

### 1. WmoZaak lifecycle

```
WmoZaak (melding → onderzoek → beschikking → uitvoering → evaluatie)
├─ Indicatiestelling (one per zaak; may have multiple evaluaties)
├─ avgClassificatie (mandatory at creation)
├─ toestemming (if sharing assessed data with external zorgaanbieder)
└─ auditLog (every read of medisch/gezinssituatie data)
```

**Example flow:**
- Cliënt bouwt telefonisch aan wijkteam → WmoZaak created with status "melding"
- WMO-consulent voert huisbezoek uit → status "onderzoek-loopt", onderzoeksverslag uploaded
- Consulent stelt indicatie op (soort: huishoudelijke-hulp, 4 uur/wk, 12 maanden) → status "beschikking-voorbereiding"
- Beschikking generated, cliënt receives → status "beschikking-verleend"
- Uitvoering start with zorgaanbieder (toestemming recorded if sharing case data) → status "uitvoering"
- After 12 months, evaluatie → status "afgesloten", vernietigingsDatum berekend

### 2. JeugdwetZaak lifecycle

```
JeugdwetZaak (melding → gezinsplan → ondersteuning → evaluatie → mogelijke verlenging)
├─ Gezinsplan (mandatory; holds family agreement, objectives, inzet trajectories)
├─ MdoOverleg (0..N MDO conferences; each records external deelnemer toestemmingen)
├─ avgClassificatie (mandatory; often multiple categories: medisch, gezinssituatie)
├─ toestemming (per external party: school, GGD, etc.)
└─ auditLog (comprehensive, given involvement of children & family trauma data)
```

**Example flow:**
- Huisarts refers child (behavioral issues post-divorce) → JeugdwetZaak created, status "melding"
- Jeugdteam ontvangt → status "gezinsplan-opstellen"
- Consulent vult gezinsplan in (doelen: communicatie verbeteren, schoolresultaten stabiliseren; trajectories: ambulante jeugdhulp via Jeugdzorg West) → status "gezinsplan-gereed"
- Parents + (if 16+) child signs consent → status "ondersteuning-gestart"
- Jeugdzorg West begins interventions; MDO conference scheduled with school maatschappelijk werker + GGD jeugdarts
- MDO verslag recorded, deelnemer toestemmingen logged → status "ondersteuning-loopt"
- Evaluatie at 6 months → status "evaluatie"
- If improvement insufficient, verlenging aangemaakt (new Gezinsplan.verlengingHistorie entry, new family consent round) → status "gezinsplan-verlengd"
- After final evaluatie → status "afgesloten", vernietigingsDatum berekend (20 jaar)

### 3. ParticipatiewetZaak lifecycle

```
ParticipatiewetZaak (aanvraag → toetsing → beschikking → re-integratie → uitvoering)
├─ ReIntegratieTraject (created if vermogen-toets OK + inkomen < norm)
│  ├─ Instrumenten (wage subsidy, training, coaching, etc.)
│  └─ Evaluatiemomenten (quarterly or per contract)
├─ avgClassificatie (mandatory; financieel + sometimes medisch/gezinsituatie)
└─ auditLog (financial data highly sensitive)
```

**Example flow:**
- Alleenstaande parent applies for bijstand → ParticipatiewetZaak created, status "aanvraag-ontvangen"
- Klantmanager runs vermogentoets (€2400 asset, under €6505 threshold → OK) + inkomstentoets (€0 income vs €1234.45 norm → OK) → status "toetsing-afgerond"
- Beschikking generated → status "beschikking-gereed"
- Re-integratie assigned (werkfit-maken trajectory) with wage subsidy (60% LW subsidy, 12 months) + training (Heftruckchauffeur, €1850 budget) + job coaching → ReIntegratieTraject created
- Monthly contact with UWV, Werkbedrijf, job coach → status "re-integratie-loopt"
- Quarterly evaluatie → ReIntegratieTraject.evaluatieMomenten updated
- If employment achieved, trajekt beëindigd; zaak archived with 10-year retention

## AVG/Access control architecture

All three zaaktypes inherit the same AVG-compliance and access-control framework:

### avgClassificatie block (mandatory, enforced in validation)

Every zaak creation must include:

```json
{
  "categorieen": ["medisch", "financieel"],           // which AVG art 9 categories
  "bijzonderePersoonsgegevens": true,                 // flag for system audit
  "rechtvaardiging": "artikel-9-2-h-avg",           // legal exemption (e.g. health/social work)
  "rechtvaardigingToelichting": "...",               // plain Dutch why-text for FG audit
  "bewaarTermijnJaren": 15,                          // WMO: 15, Jeugdwet: 20, Participatiewet: 10
  "vernietigingDatum": "2041-03-15",                 // auto-calculated on closure
  "toegangsBeperking": "alleen-behandelaar-en-wijkteam",  // hardcoded in query guards
  "anonimiseringBijDelen": true,                     // auto-mask on export unless toestemming
  "exportBeperking": "geen-bulk-export"              // no CSV dumps of this zaak
}
```

This block is **not optional** — the zaak creation API returns 400 if missing.

### Access guards (hardcoded, not RBAC)

Query-level checks before any zaak content is returned:

1. **Team membership check** → if zaak.wijkteam != user.wijkteam, only return metadata (zaak number, status, dates), block all content fields
2. **Second-handler exception** → if user.id == zaak.tweedeBehandelaarId, grant full access
3. **FG-audit mode** → if user.role == "functionaris-gegevensbescherming" AND intent == "audit", return metadata + auditLog without content, logged as "FG-audit"
4. **Anonymization-on-export** → if export-request && !toestemming.gegeven, run pii-detection-masking on all PII fields (BSN, geboortedatum, gezinssituatie, medisch details) before returning

### Toestemming (consent) entity

When a zaak's content needs to be shared with an externe party:

```json
{
  "zaakId": "zaak-2026-jeugd-00921",
  "verleendDoorBsn": "111222333",          // the citizen/parent who consented
  "verleendDatum": "2026-03-05",
  "geldigTot": "2026-09-05",               // consent expires
  "tePartijen": ["Jeugdzorg West", "Basisschool De Vlinder"],  // list of recipients
  "tegegevens": ["gezinsplan-doelen", "evaluatie-momenten"],   // specific fields OK'd
  "tedoel": "Afstemming jeugdhulp en schoolsituatie",
  "intrekkingMogelijk": true,              // can be revoked
  "ingetrokken": false
}
```

**System behavior:**
- Export without toestemming → auto-anonymize
- Export with toestemming → send identified data
- Toestemming expires → future exports auto-anonymize again

### Audit logging

Every read-action on a zaak with bijzondere persoonsgegevens:

```json
{
  "zaakId": "zaak-2026-wmo-04832",
  "medewerkerId": "medewerker-892",
  "actie": "read",
  "tijdstip": "2026-04-22T14:32:00Z",
  "ipAdres": "192.168.1.100",
  "geraadpleegdeVelden": ["ondersteuningsvraag", "huishoudensSamenstelling", "indicatiestelling"]
}
```

Logged to support:
- Subject-access requests (citizen: "who has seen my data?")
- FG-audit (functionaris: "did only authorized staff access this zaak?")

## OR abstraction usage

Per ADR-022, sociaal-domein zaaktypes consume (not reimplement):

| OR abstraction | Usage in sociaal-domein |
|---|---|
| Registers + schemas | ✓ WmoZaak, JeugdwetZaak, ParticipatiewetZaak, Indicatiestelling, Gezinsplan, ReIntegratieTraject, MdoOverleg, Toestemming |
| RBAC (authorization) | ✓ (but overridden by datadriven wijkteam guard) |
| Audit trail (immutable) | ✓ full auditTrail on all entities; + dedicated auditLog for PII-access |
| Archival + destruction (retention) | ✓ vernietigingsDatum calculated per zaaktype's bewaarTermijn; batch-job generates voorstel-s |
| `x-openregister-lifecycle` | ✓ every zaaktype's statusFlow declared as lifecycle block |
| `x-openregister-aggregations` | ✓ for wijkteam-dashboard (caseload count, doorlooptijd stats) |

## Seed data examples

Per ADR-000 & design convention, each spec includes 3–5 realistic Dutch-language seed objects per entity type.

**WmoZaak examples:**
- Mevrouw Janssen-de Vries (age 75+, post-heupoperatie, needs 4 hr/wk huishoudelijke hulp)
- Dhr. Piet Bakker (age 65+, mild dementia, family requests dagbesteding + begeleiding)
- Young parent (post-partum depression, requesting hulpverlening, wijkteam-oost)

**JeugdwetZaak examples:**
- 9-year-old post-divorce behavioral issues (ambulante jeugdhulp, family-wide MDO)
- 16-year-old school refusal + depression (inzet outreachend jeugdwerk + jeugdpsych)
- Toddler developmental delay (early intervention trajectory, school-FE link)

**ParticipatiewetZaak examples:**
- Young single parent (bijstand, re-integratie with wage subsidy)
- 58-year-old transitioning from long-term sickness benefit (loonkostensubsidie + job coaching)
- Recent immigrant (inburgering-linked participation pathway)

Each seed object includes realistic dates, BSN pseudonyms, wijkteam assignments, and appropriate statusHistory.

## Implementation sequence (per ADR-032)

1. **This change (config):** Specs authored, no code.
2. **Wave 1 (code chains, parallel):**
   - Register-patch landing WmoZaak + Indicatiestelling schema
   - Register-patch landing JeugdwetZaak + Gezinsplan + MdoOverleg schema
   - Register-patch landing ParticipatiewetZaak + ReIntegratieTraject schema
   - Common patch landing Toestemming + AvgClassificatie schemas
3. **Wave 2 (code chains, sequential):**
   - Access-guard implementation in zaak-read endpoints (wijkteam check, FG-audit check)
   - Audit-log instrumentation on all read-actions
   - Retention-calculation + vernietigingsvoorstel-generation batch job
4. **Wave 3 (UI, optional):**
   - Wijkteam dashboard in launchpad (caseload, doorlooptijden per zaaktype)
   - Beschikking-generation templates in docudesk for Wmo/Jeugdwet/Participatiewet
   - openconnector sources for iWMO/iJW berichtenverkeer

