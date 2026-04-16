# Design: inspection-checklists

## Architecture Overview

Inspection checklists are OpenRegister objects (`inspectieChecklist`) linked to case types. Inspectors fill out inspection reports (`inspectieRapport`) on case instances. The overall result (conform/niet_conform/deels_conform) is auto-calculated by `InspectieService` from item results and can trigger workflow transitions.

```
AdminRoot.vue
└── InspectieChecklistList.vue   (admin: list/manage templates per case type)
    └── InspectieChecklistDetail.vue  (admin: edit items, publish/archive versions)

CaseDetail.vue
└── InspectieSection.vue         (case: list reports, start new inspection)
    ├── InspectieRapportForm.vue  (guided execution: item-by-item, photos, GPS)
    └── InspectieRapportDetail.vue (view completed report with item results + photos)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/InspectieService.php` | Checklist versioning, result auto-calculation, statistical sampling selection, approval task creation |
| `lib/Controller/InspectieChecklistController.php` | CRUD for `inspectieChecklist` templates; publish/archive lifecycle actions |
| `lib/Controller/InspectieRapportController.php` | CRUD for `inspectieRapport`; submit action with result calculation |
| `src/views/settings/tabs/InspectieChecklistList.vue` | Admin: list checklist templates, filter by case type, status badge |
| `src/views/settings/tabs/InspectieChecklistDetail.vue` | Admin: edit checklist items (drag-reorder, type selector, required/foto toggles), publish/archive |
| `src/views/cases/components/InspectieSection.vue` | Case detail: list inspection reports with result badges; "Nieuwe inspectie" button |
| `src/views/cases/components/InspectieRapportForm.vue` | Guided inspection form: step through each item, photo upload per item, GPS capture, submit |
| `src/views/cases/components/InspectieRapportDetail.vue` | View completed report: per-item results, photo gallery, overall result badge, approval status |
| `src/services/inspectieApi.js` | Frontend API service for all inspection endpoints |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add seed data objects for `inspectieChecklist` and `inspectieRapport` schemas |
| `appinfo/routes.php` | Add inspection API routes (11 routes) |
| `src/views/cases/CaseDetail.vue` | Import and render `InspectieSection` in the case detail view |
| `src/views/settings/AdminRoot.vue` | Add `InspectieChecklistList` as a settings tab |

## Design Decisions

### DD-01: Checklist versioning — edit creates a new version

**Decision**: When an active `inspectieChecklist` is modified, `InspectieService` increments the `version` field and sets the old version to `archived`. Existing `inspectieRapport` records retain the UUID reference to the checklist version used at inspection time.

**Rationale**: Audit compliance (AVG/archief) requires that completed inspection reports reflect the checklist exactly as it existed at inspection time. Retroactively altering completed reports by modifying the template is prohibited.

### DD-02: Result auto-calculation from item outcomes

**Decision**: `InspectieService::calculateResult()` derives the overall `result` as follows:
- `conform` — all required items have a passing result (ja / within-range getal / any tekst/foto/meerkeuze value present)
- `niet_conform` — one or more required items have `nee` or a failing getal measurement
- `deels_conform` — some required items pass, some fail (partial compliance)

`failedItems` is set to the count of failing required items.

**Rationale**: Removes manual result entry. Inspectors record facts; the system derives the judgment. Matches common VTH practice for bouwtoezicht and milieu-inspectie.

### DD-03: Statistical sampling as a filtered item subset

**Decision**: Statistical sampling is implemented by marking items with `"sample": true` in the checklist template. `InspectieService::selectSampleItems()` randomly selects a configured percentage (or fixed count) of sample-marked items for each sampling inspection round. The selection is recorded in `inspectieRapport.items` so the sampled subset is auditable.

**Rationale**: Keeps sampling logic within the existing `inspectieChecklist.items` array structure. No additional entity is needed. Aligns with ISO 2859 attribute sampling used in Dutch VTH practice for release-quality inspection of construction phases.

### DD-04: Approval gate via task on the case

**Decision**: When `followUpRequired` is `true` (set automatically when `result` is `niet_conform` or when the checklist `items` includes an item with `type: "approval_gate"`), `InspectieService` creates a `task` object on the case: title "Goedkeuring inspectie vereist — [inspector name]", assigned to the supervisor role (roleType: toezichthouder), with a reference to the `inspectieRapport` UUID in the task description.

**Rationale**: Reuses the existing `task` entity and `TasksController` — no custom approval entity needed. The supervisor marks the task complete to release the hold. Consistent with how other approval gates work in Procest (parafering, adviesAanvraag).

### DD-05: Photos via OpenRegister file attachments on the rapport

**Decision**: Photos are uploaded as Nextcloud file attachments on the `inspectieRapport` object via the existing `FileService`. File IDs are stored in `inspectieRapport.photos` (array) and cross-referenced per item in `inspectieRapport.items[n].photos`.

**Rationale**: Consistent with ADR-001 (all data in OpenRegister). No custom file upload handler. Photos inherit the case's retention policy automatically.

## Reuse Analysis

| Capability | Platform Component | Used For |
|---|---|---|
| CRUD REST API | ObjectService | `inspectieChecklist` + `inspectieRapport` create/read/update/delete |
| File attachments | FileService + CnFilesTab | Photo uploads per inspection item |
| Task creation | TasksController | Supervisor approval gate on niet_conform result |
| Workflow triggers | WorkflowEngineController | Case status transition after inspection submission |
| Audit trail | AuditTrailService | Immutable inspection history (who changed what, when) |
| Schema-driven forms | CnFormDialog | Checklist template basic editing |
| List + pagination | CnDataTable + useListView | Checklist template list and inspection report list |
| Notifications | NotificationService | Alert supervisor on niet_conform result |
| Object store | createObjectStore | `inspectieChecklistStore` + `inspectieRapportStore` Pinia stores |

No overlap found with existing Procest services. `InspectieService` introduces new domain logic (versioning, result calculation, sampling) not covered by any existing service.

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/inspectie-checklists` | List templates (filter: caseType, status) |
| POST | `/api/inspectie-checklists` | Create checklist template |
| GET | `/api/inspectie-checklists/{id}` | Get checklist template |
| PUT | `/api/inspectie-checklists/{id}` | Update (creates new version if active) |
| POST | `/api/inspectie-checklists/{id}/publish` | Set status → active |
| POST | `/api/inspectie-checklists/{id}/archive` | Set status → archived |
| GET | `/api/inspectie-rapporten` | List reports (filter: case, checklist, result) |
| POST | `/api/inspectie-rapporten` | Start inspection report |
| GET | `/api/inspectie-rapporten/{id}` | Get inspection report |
| PUT | `/api/inspectie-rapporten/{id}` | Update in-progress report |
| POST | `/api/inspectie-rapporten/{id}/submit` | Submit and calculate result |

## Seed Data

> Seed data MUST be added to `lib/Settings/procest_register.json` using the `@self` envelope per ADR seed data requirements. The objects below use `@ref:` notation for cross-references — replace with actual slugs/UUIDs during implementation.

### inspectieChecklist — 3 objects

**Object 1: Bouwtoezicht fase 1 — Fundering**

```json
{
  "@self": {
    "register": "procest",
    "schema": "inspectieChecklist",
    "slug": "bouwtoezicht-fundering-v1"
  },
  "name": "Bouwtoezicht fase 1 — Fundering",
  "caseType": "omgevingsvergunning-bouw",
  "version": 1,
  "status": "active",
  "items": [
    {
      "order": 1,
      "label": "Fundering conform bouwtekening aangelegd",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": true,
      "helpText": "Controleer diepte en positie ten opzichte van tekening"
    },
    {
      "order": 2,
      "label": "Betonsterkte conform bestek (min. C20/25)",
      "type": "getal",
      "required": true,
      "fotoRequired": false,
      "helpText": "Voer gemeten waarde in MPa in. Norm: minimaal 20 MPa."
    },
    {
      "order": 3,
      "label": "Wapening correct geplaatst en gedekt",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": true,
      "helpText": "Dekking minimaal 25mm voor binnenwanden, 35mm voor buitenwanden"
    },
    {
      "order": 4,
      "label": "Drainage- of folieafdichting aanwezig",
      "type": "ja_nee_nvt",
      "required": false,
      "fotoRequired": false,
      "helpText": "Alleen van toepassing bij hoge grondwaterstand"
    },
    {
      "order": 5,
      "label": "Overige opmerkingen inspector",
      "type": "tekst",
      "required": false,
      "fotoRequired": false,
      "helpText": ""
    }
  ]
}
```

**Object 2: Brandveiligheid controle — Horeca**

```json
{
  "@self": {
    "register": "procest",
    "schema": "inspectieChecklist",
    "slug": "brandveiligheid-horeca-v1"
  },
  "name": "Brandveiligheid controle — Horeca",
  "caseType": "milieu-inrichting",
  "version": 1,
  "status": "active",
  "items": [
    {
      "order": 1,
      "label": "Vluchtwegen vrij van obstakels en duidelijk bewegwijzerd",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": true,
      "helpText": "Minimale vrije breedte: 0,85m conform NEN 6088"
    },
    {
      "order": 2,
      "label": "Brandblussers aanwezig, zichtbaar en gekeurd (keuring ≤ 1 jaar oud)",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": true,
      "helpText": "Controleer keuringslabel op datum"
    },
    {
      "order": 3,
      "label": "Rookmelders functioneel (testknop ter plaatse getest)",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": false,
      "helpText": ""
    },
    {
      "order": 4,
      "label": "Maximaal toegestaan aantal bezoekers zichtbaar aangegeven",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": false,
      "helpText": "Conform vergunningsvoorschrift — zie zaakdossier voor vergund aantal"
    },
    {
      "order": 5,
      "label": "Keukenhoed en vetfilters schoon en gekeurd",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": true,
      "helpText": "Jaarlijkse reiniging verplicht; controleer reinigingsrapport"
    }
  ]
}
```

**Object 3: Milieu bodemkwaliteit — steekproef controle**

```json
{
  "@self": {
    "register": "procest",
    "schema": "inspectieChecklist",
    "slug": "milieu-bodem-steekproef-v1"
  },
  "name": "Milieu bodemkwaliteit — steekproef controle",
  "caseType": "handhaving-milieu",
  "version": 1,
  "status": "active",
  "items": [
    {
      "order": 1,
      "label": "Monsterlocatie conform bemonsterings­protocol geselecteerd",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": true,
      "helpText": "NEN 5740 gridmethode; locatie fotografisch vastleggen"
    },
    {
      "order": 2,
      "label": "pH-waarde bodem (gemeten ter plaatse)",
      "type": "getal",
      "required": true,
      "fotoRequired": false,
      "helpText": "Typische achtergrondwaarde 5,5–7,5. Waarden buiten 5,0–8,0 zijn afwijkend."
    },
    {
      "order": 3,
      "label": "Zichtbare verontreiniging aangetroffen (olie, afval, vreemde kleur/geur)",
      "type": "ja_nee_nvt",
      "required": true,
      "fotoRequired": true,
      "helpText": "Bij 'ja': fotografeer en noteer zone in opmerkingenveld"
    },
    {
      "order": 4,
      "label": "Grondwaterstand (cm beneden maaiveld)",
      "type": "getal",
      "required": false,
      "fotoRequired": false,
      "helpText": "Meting via peilbuis of grove schatting"
    },
    {
      "order": 5,
      "label": "Beschrijving aangetroffen situatie en advies vervolgstap",
      "type": "tekst",
      "required": true,
      "fotoRequired": false,
      "helpText": "Geef per zone een korte omschrijving en aanbeveling"
    }
  ]
}
```

### inspectieRapport — 3 objects

**Object 1: Conform rapport — bouwtoezicht fundering**

```json
{
  "@self": {
    "register": "procest",
    "schema": "inspectieRapport",
    "slug": "rapport-bouwtoezicht-2026-0042"
  },
  "case": "bouwvergunning-2026-0042",
  "checklist": "bouwtoezicht-fundering-v1",
  "inspector": "j.vanderberg",
  "inspectionDate": "2026-03-15T10:30:00+01:00",
  "location": "52.3721,4.8986",
  "result": "conform",
  "failedItems": 0,
  "items": [
    { "itemId": 1, "result": "ja", "comment": "Fundering aangelegd op -1,20m, conform tekening R-001", "photos": [] },
    { "itemId": 2, "result": 28.5, "comment": "Beton C28/35 geleverd; voldoet aan minimale eis C20/25", "photos": [] },
    { "itemId": 3, "result": "ja", "comment": "Wapening Ø12 correct gedekt, dekking 30mm gemeten", "photos": [] },
    { "itemId": 4, "result": "nvt", "comment": "Grondwaterstand laag, geen drainage vereist", "photos": [] },
    { "itemId": 5, "result": "Geen bijzonderheden. Vrijgave fase 1 aanbevolen.", "comment": "", "photos": [] }
  ],
  "remarks": "Fundering voldoet volledig aan bestek en bouwtekening. Aanbeveling: vrijgave storten betonvloer fase 2.",
  "followUpRequired": false
}
```

**Object 2: Niet conform rapport — brandveiligheid horeca**

```json
{
  "@self": {
    "register": "procest",
    "schema": "inspectieRapport",
    "slug": "rapport-brandveiligheid-2026-0089"
  },
  "case": "milieu-inrichting-2026-0089",
  "checklist": "brandveiligheid-horeca-v1",
  "inspector": "m.dejong",
  "inspectionDate": "2026-03-22T14:00:00+01:00",
  "location": "Kalverstraat 5, 1012 NX Amsterdam",
  "result": "niet_conform",
  "failedItems": 2,
  "items": [
    { "itemId": 1, "result": "ja", "comment": "Vluchtwegen vrij, twee uitgangen duidelijk gemarkeerd", "photos": [] },
    { "itemId": 2, "result": "nee", "comment": "Twee van drie brandblussers verlopen (keuringsdatum okt 2024). Derde bluster ontbreekt.", "photos": [] },
    { "itemId": 3, "result": "ja", "comment": "Alle rookmelders functioneel getest", "photos": [] },
    { "itemId": 4, "result": "nee", "comment": "Geen aanduiding maximumbezetting aangetroffen in hal of bij ingang", "photos": [] },
    { "itemId": 5, "result": "ja", "comment": "Vetfilters gereinigd februari 2026, reinigingsrapport aanwezig", "photos": [] }
  ],
  "remarks": "Twee verplichte voorschriften niet nageleefd. Herstel vereist vóór herinspectie. Termijn: 4 weken.",
  "followUpRequired": true
}
```

**Object 3: Deels conform rapport — milieu bodemkwaliteit**

```json
{
  "@self": {
    "register": "procest",
    "schema": "inspectieRapport",
    "slug": "rapport-bodem-2026-0103"
  },
  "case": "handhaving-milieu-2026-0103",
  "checklist": "milieu-bodem-steekproef-v1",
  "inspector": "a.smit",
  "inspectionDate": "2026-04-01T09:00:00+02:00",
  "location": "Industrieweg 45, 3542 AD Utrecht",
  "result": "deels_conform",
  "failedItems": 1,
  "items": [
    { "itemId": 1, "result": "ja", "comment": "Monsterlocaties conform NEN 5740 gridmethode geselecteerd (4 zones)", "photos": [] },
    { "itemId": 2, "result": 6.2, "comment": "pH 6,2; binnen norm", "photos": [] },
    { "itemId": 3, "result": "ja", "comment": "Lichte oliesporen in zone C geconstateerd (geur, kleurverkleuring)", "photos": [] },
    { "itemId": 4, "result": 85, "comment": "Grondwaterstand 85cm beneden maaiveld, peilbuis aanwezig", "photos": [] },
    { "itemId": 5, "result": "Zones A, B en D conform. Zone C: lichte petroleumverontreiniging. Nader bodemonderzoek zone C aanbevolen (NEN 5740 fase 2).", "comment": "", "photos": [] }
  ],
  "remarks": "Deels conform. Drie van vier zones voldoen. Nader onderzoek deelzone C noodzakelijk voor volledige vrijgave.",
  "followUpRequired": true
}
```
