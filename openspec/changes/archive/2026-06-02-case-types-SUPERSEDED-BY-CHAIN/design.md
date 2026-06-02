## Architecture

### Data Layer

All case type sub-entities are stored as OpenRegister objects linked to their parent case type
via the `caseType` reference field. The hierarchy per case type instance is:

```
caseType (UUID: e.g. ct-omgevingsvergunning)
├── statusType[]       — 3–5 ordered lifecycle phases (caseType: <uuid>)
├── resultType[]       — 2–5 outcome types with archival rules (caseType: <uuid>)
├── roleType[]         — 2–4 participant role definitions (caseType: <uuid>)
├── propertyDefinition[] — 0–n custom field definitions (caseType: <uuid>)
├── documentType[]     — 0–n required document checklist entries (caseType: <uuid>)
└── decisionType[]     — 0–n allowed decision types (caseType: <uuid>)
```

All six schemas are already registered in `procest_register.json` and mapped in
`SettingsService::SLUG_TO_CONFIG_KEY`. No schema migrations are required for this change.

Sub-entity queries use `ObjectService::findObjects($register, $schema, ['caseType' => $uuid])`
on the backend and `objectStore.getObjects({ caseType: uuid })` on the frontend.

### Frontend Changes

**Five new tab components** in `src/views/settings/tabs/`:

#### ResultTypesTab.vue

- Renders list of result types for the active case type (forward lookup: resultType.caseType = ct)
- Table columns: `name`, `archiveAction` (badge: retain → green, destroy → red),
  `retentionPeriod` (formatted from ISO 8601 via `durationHelpers.js`), `retentionDateSource`
- Add button → `CnFormDialog` (schema-driven) with fields:
  `name` (required), `description`, `archiveAction` (select: retain/destroy),
  `retentionPeriod` (ISO 8601 text input with hint), `retentionDateSource` (select, 8 options:
  case_completed, decision_effective, decision_expiry, fixed_period, related_case, parent_case,
  custom_property, custom_date)
- Row actions: Edit (`CnFormDialog`), Delete (`CnDeleteDialog` — warn if referenced by
  closed cases)

#### RoleTypesTab.vue

- Renders list of role types for the active case type
- Table columns: `name`, `genericRole` (translated badge)
- Add button → `CnFormDialog` with fields:
  `name` (required), `description`, `genericRole` (select: initiator, handler, advisor,
  decision_maker, stakeholder, coordinator, contact, co_initiator)
- Row actions: Edit, Delete

#### PropertiesTab.vue

- Renders list of property definitions for the active case type
- Table columns: `name`, `propertyType` (format badge: text/number/date/datetime),
  `isRequired` (boolean icon), `defaultValue` (truncated if set)
- Add button → `CnFormDialog` with fields:
  `name` (required), `definition`, `description`, `propertyType` (select: text/number/date/datetime),
  `isRequired` (checkbox), `defaultValue`
- Row actions: Edit, Delete

#### DocumentTypesTab.vue

- Renders list of document types for the active case type
- Table columns: `name`, `category`, `direction` (badge: incoming ← / internal ↔ / outgoing →),
  `isRequired` (boolean icon), `confidentiality`
- Add button → `CnFormDialog` with fields:
  `name` (required), `description`, `category`, `direction` (select: incoming/internal/outgoing),
  `isRequired` (checkbox), `confidentiality` (select), `allowedMimeTypes` (tags input),
  `validFrom`, `validUntil`
- Row actions: Edit, Delete

#### DecisionTypesTab.vue

- Renders list of decision types for the active case type
- Table columns: `name`, `isDraft` (badge), `publicationRequired` (boolean icon),
  `validFrom`, `validUntil`
- Add button → `CnFormDialog` with fields:
  `name` (required), `description`, `isDraft` (checkbox), `publicationRequired` (checkbox),
  `validFrom`, `validUntil`
- Row actions: Edit, Delete

**Modified: `src/views/settings/CaseTypeDetail.vue`**

Add five tab entries to the existing `NcTabPanel` navigation:

```
General | Statuses | Results | Roles | Properties | Docs | Decisions
```

Pass `caseTypeId` as prop to each new tab component. Each tab component independently
fetches its own sub-entities using the `objectStore` registered for that schema type.

**Modified: `src/store/store.js`**

Register five additional entity types (if not already registered):

```js
objectStore.registerObjectType('result-type', 'resultType', 'procest')
objectStore.registerObjectType('role-type', 'roleType', 'procest')
objectStore.registerObjectType('property-definition', 'propertyDefinition', 'procest')
objectStore.registerObjectType('document-type', 'documentType', 'procest')
objectStore.registerObjectType('decision-type', 'decisionType', 'procest')
```

Type names: kebab-case per ADR-015 store registration pattern.

### Backend Changes

**`lib/Service/ZgwZtcRulesService.php`** (extend existing service):

Add `validatePublish(string $caseTypeId): array` method:
1. Load all statusType objects where `caseType = $caseTypeId`
2. If count === 0 → add error: "At least one status type must be defined"
3. If no statusType has `isFinal = true` → add error: "At least one status type must be marked as final"
4. Load the caseType object; if `validFrom` is empty → add error: "'Valid from' date must be set"
5. Return array of error strings (empty = valid)

Hook into the publish path: when `isDraft` transitions from `true` to `false` via
`ObjectService::saveObject()`, call `validatePublish()` and return HTTP 422 with structured
errors if the array is non-empty.

Add `validateDeletion(string $caseTypeId): array` method:
1. Count case objects where `caseType = $caseTypeId` and status is non-final
2. If count > 0 → return error: "Cannot delete case type: {n} active cases are using this type"
3. Count case objects where `caseType = $caseTypeId` (all) — if > 0 → return warning for
   confirmation flow

### Seed Data

Defined in `lib/Settings/procest_register.json` under `components.objects[]`
with `x-openregister: { type: "mock" }`. Uses `@self` envelope with slug-based idempotency.

---

#### caseType objects (4 objects)

**omgevingsvergunning-zaaktype**
```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "ct-omgevingsvergunning" },
  "title": "Omgevingsvergunning",
  "description": "Behandeling van aanvragen voor omgevingsvergunningen voor bouwen, slopen en aanleggen.",
  "purpose": "Beoordelen en beslissen op bouwgerelateerde aanvragen van burgers en bedrijven.",
  "trigger": "Aanvraag van burger of bedrijf via DSO of balie.",
  "subject": "Bouw-, sloop- en aanlegactiviteiten in de gemeente.",
  "processingDeadline": "P56D",
  "confidentiality": "intern",
  "isDraft": false,
  "validFrom": "2026-01-01",
  "origin": "extern",
  "extensionAllowed": true,
  "extensionPeriod": "P14D",
  "suspensionAllowed": true,
  "responsible": "Afdeling Vergunningen",
  "publicationRequired": true
}
```

**subsidieaanvraag-zaaktype**
```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "ct-subsidieaanvraag" },
  "title": "Subsidieaanvraag",
  "description": "Behandeling van aanvragen voor gemeentelijke subsidies op het gebied van duurzaamheid, sport en cultuur.",
  "purpose": "Beoordelen van subsidieaanvragen en beschikken op basis van subsidieverordening.",
  "trigger": "Aanvraag ingediend via het subsidieportaal of per post.",
  "subject": "Gemeentelijke subsidies voor inwoners en organisaties.",
  "processingDeadline": "P13W",
  "confidentiality": "beperkt openbaar",
  "isDraft": false,
  "validFrom": "2026-01-01",
  "origin": "extern",
  "extensionAllowed": false,
  "suspensionAllowed": false,
  "responsible": "Afdeling Sociale Zaken",
  "publicationRequired": false
}
```

**klachtbehandeling-zaaktype**
```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "ct-klachtbehandeling" },
  "title": "Klachtbehandeling",
  "description": "Behandeling van klachten over het gedrag van medewerkers of de dienstverlening van de gemeente.",
  "purpose": "Klachten tijdig en zorgvuldig behandelen conform Hoofdstuk 9 Awb.",
  "trigger": "Klacht ingediend door burger of ondernemer via loket, e-mail of post.",
  "subject": "Gedragingen van bestuursorganen en medewerkers.",
  "processingDeadline": "P6W",
  "confidentiality": "openbaar",
  "isDraft": false,
  "validFrom": "2026-01-01",
  "origin": "extern",
  "extensionAllowed": true,
  "extensionPeriod": "P4W",
  "suspensionAllowed": false,
  "responsible": "Klachtencoördinator",
  "publicationRequired": false
}
```

**bezwaarschrift-zaaktype**
```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "ct-bezwaarschrift" },
  "title": "Bezwaarschrift",
  "description": "Behandeling van bezwaarschriften tegen besluiten van de gemeente conform Awb hoofdstuk 7.",
  "purpose": "Heroverweging van genomen besluiten op grond van ingediend bezwaar.",
  "trigger": "Bezwaarschrift ingediend binnen 6 weken na bekendmaking besluit.",
  "subject": "Besluiten van het bestuursorgaan waartegen bezwaar is ingediend.",
  "processingDeadline": "P6W",
  "confidentiality": "intern",
  "isDraft": false,
  "validFrom": "2026-01-01",
  "origin": "extern",
  "extensionAllowed": true,
  "extensionPeriod": "P6W",
  "suspensionAllowed": true,
  "responsible": "Bezwaarcoördinator",
  "publicationRequired": false
}
```

---

#### statusType objects (4 per case type = 16 total)

**Omgevingsvergunning statuses:**
```json
[
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-omgv-ontvangen" },
    "name": "Ontvangen", "caseType": "ct-omgevingsvergunning", "order": 1, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-omgv-in-behandeling" },
    "name": "In behandeling", "caseType": "ct-omgevingsvergunning", "order": 2, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-omgv-besluitvorming" },
    "name": "Besluitvorming", "caseType": "ct-omgevingsvergunning", "order": 3, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-omgv-afgehandeld" },
    "name": "Afgehandeld", "caseType": "ct-omgevingsvergunning", "order": 4, "isFinal": true }
]
```

**Subsidieaanvraag statuses:**
```json
[
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-sub-ontvangen" },
    "name": "Ontvangen", "caseType": "ct-subsidieaanvraag", "order": 1, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-sub-in-beoordeling" },
    "name": "In beoordeling", "caseType": "ct-subsidieaanvraag", "order": 2, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-sub-beschikt" },
    "name": "Beschikt", "caseType": "ct-subsidieaanvraag", "order": 3, "isFinal": true }
]
```

**Klachtbehandeling statuses:**
```json
[
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-kl-ingediend" },
    "name": "Ingediend", "caseType": "ct-klachtbehandeling", "order": 1, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-kl-in-onderzoek" },
    "name": "In onderzoek", "caseType": "ct-klachtbehandeling", "order": 2, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-kl-afgerond" },
    "name": "Afgerond", "caseType": "ct-klachtbehandeling", "order": 3, "isFinal": true }
]
```

**Bezwaarschrift statuses:**
```json
[
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-bz-ontvangen" },
    "name": "Ontvangen", "caseType": "ct-bezwaarschrift", "order": 1, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-bz-in-behandeling" },
    "name": "In behandeling", "caseType": "ct-bezwaarschrift", "order": 2, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-bz-hoorzitting" },
    "name": "Hoorzitting", "caseType": "ct-bezwaarschrift", "order": 3, "isFinal": false },
  { "@self": { "register": "procest", "schema": "statusType", "slug": "st-bz-besloten" },
    "name": "Besloten", "caseType": "ct-bezwaarschrift", "order": 4, "isFinal": true }
]
```

---

#### resultType objects (3 per case type = 12 total)

**Omgevingsvergunning results:**
```json
[
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-omgv-verleend" },
    "name": "Vergunning verleend", "caseType": "ct-omgevingsvergunning",
    "archivalAction": "blijvend_bewaren", "archivalPeriod": "P20Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-omgv-geweigerd" },
    "name": "Vergunning geweigerd", "caseType": "ct-omgevingsvergunning",
    "archivalAction": "vernietigen", "archivalPeriod": "P10Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-omgv-ingetrokken" },
    "name": "Aanvraag ingetrokken", "caseType": "ct-omgevingsvergunning",
    "archivalAction": "vernietigen", "archivalPeriod": "P5Y" }
]
```

**Subsidieaanvraag results:**
```json
[
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-sub-toegekend" },
    "name": "Subsidie toegekend", "caseType": "ct-subsidieaanvraag",
    "archivalAction": "blijvend_bewaren", "archivalPeriod": "P15Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-sub-afgewezen" },
    "name": "Subsidie afgewezen", "caseType": "ct-subsidieaanvraag",
    "archivalAction": "vernietigen", "archivalPeriod": "P10Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-sub-ingetrokken" },
    "name": "Aanvraag ingetrokken", "caseType": "ct-subsidieaanvraag",
    "archivalAction": "vernietigen", "archivalPeriod": "P5Y" }
]
```

**Klachtbehandeling results:**
```json
[
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-kl-gegrond" },
    "name": "Klacht gegrond", "caseType": "ct-klachtbehandeling",
    "archivalAction": "blijvend_bewaren", "archivalPeriod": "P10Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-kl-ongegrond" },
    "name": "Klacht ongegrond", "caseType": "ct-klachtbehandeling",
    "archivalAction": "vernietigen", "archivalPeriod": "P5Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-kl-ingetrokken" },
    "name": "Klacht ingetrokken", "caseType": "ct-klachtbehandeling",
    "archivalAction": "vernietigen", "archivalPeriod": "P2Y" }
]
```

**Bezwaarschrift results:**
```json
[
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-bz-gegrond" },
    "name": "Bezwaar gegrond", "caseType": "ct-bezwaarschrift",
    "archivalAction": "blijvend_bewaren", "archivalPeriod": "P20Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-bz-ongegrond" },
    "name": "Bezwaar ongegrond", "caseType": "ct-bezwaarschrift",
    "archivalAction": "vernietigen", "archivalPeriod": "P10Y" },
  { "@self": { "register": "procest", "schema": "resultType", "slug": "rt-bz-niet-ontvankelijk" },
    "name": "Niet-ontvankelijk", "caseType": "ct-bezwaarschrift",
    "archivalAction": "vernietigen", "archivalPeriod": "P5Y" }
]
```

---

#### roleType objects (3–4 per case type = 13 total)

```json
[
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-omgv-aanvrager" },
    "name": "Aanvrager", "caseType": "ct-omgevingsvergunning" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-omgv-behandelaar" },
    "name": "Behandelaar", "caseType": "ct-omgevingsvergunning" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-omgv-adviseur" },
    "name": "Technisch adviseur", "caseType": "ct-omgevingsvergunning" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-omgv-beslisser" },
    "name": "Beslisser", "caseType": "ct-omgevingsvergunning" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-sub-aanvrager" },
    "name": "Aanvrager", "caseType": "ct-subsidieaanvraag" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-sub-behandelaar" },
    "name": "Behandelaar", "caseType": "ct-subsidieaanvraag" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-sub-adviseur" },
    "name": "Financieel adviseur", "caseType": "ct-subsidieaanvraag" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-kl-klager" },
    "name": "Klager", "caseType": "ct-klachtbehandeling" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-kl-behandelaar" },
    "name": "Behandelaar", "caseType": "ct-klachtbehandeling" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-kl-leidinggevende" },
    "name": "Leidinggevende", "caseType": "ct-klachtbehandeling" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-bz-bezwaarmaker" },
    "name": "Bezwaarmaker", "caseType": "ct-bezwaarschrift" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-bz-behandelaar" },
    "name": "Behandelaar", "caseType": "ct-bezwaarschrift" },
  { "@self": { "register": "procest", "schema": "roleType", "slug": "role-bz-juridisch-adviseur" },
    "name": "Juridisch adviseur", "caseType": "ct-bezwaarschrift" }
]
```

---

## Reuse Analysis

| Capability | Reused Component | Notes |
|---|---|---|
| Sub-entity CRUD (all 5 tabs) | `createObjectStore` + `CnFormDialog` + `CnDeleteDialog` | Schema-driven forms auto-generated; no custom dialogs needed |
| Tab navigation | Existing `NcTabPanel` in `CaseTypeDetail.vue` | Add tab entries only |
| List display in each tab | `CnDataTable` via `CnIndexPage` pattern | Sortable, paginated out of the box |
| ISO 8601 duration display | Existing `durationHelpers.js` | Already used in GeneralTab; reuse for retentionPeriod display |
| Status name lookup for dropdowns | Existing statusType store | For `requiredAtStatus` selects in PropertiesTab / DocumentTypesTab |
| Sub-entity fetch by caseType | `ObjectService::findObjects()` | 3-arg positional form per ADR-015 |
| Backend publish validation | Extend `ZgwZtcRulesService` | Add `validatePublish()` to existing service; no new service class |
| Active case count | `ObjectService::findObjects()` with status filter | No custom count endpoint needed |

No custom search endpoints, pagination logic, or data-fetching mechanisms.
All CRUD via `createObjectStore` + platform API.

## Decisions

1. **No new schemas**: All 6 sub-entity types are pre-defined in ADR-000 and already
   registered in `procest_register.json`. The tab components only need store registration
   and Vue UI.

2. **Schema-driven forms**: Use `CnFormDialog` auto-generated from schema for all tab
   CRUD operations — no custom form layouts or validation components needed.

3. **Seed data scope**: Seed objects are limited to flat properties per the current
   `ImportHandler` limitation (relations resolved by slug at import time). Cross-entity
   references (e.g., `caseType: "ct-omgevingsvergunning"`) use slug strings that
   `ImportHandler` resolves to UUIDs on first import.

4. **Backend validation placement**: Publish validation added to `ZgwZtcRulesService`
   rather than a new service class, to stay consistent with the existing ZTC rules pattern
   and avoid service proliferation.

5. **Deletion guard via query**: Count-based check using `findObjects()` with
   non-final status filter — no custom API endpoint or stored counter field needed.

6. **Tab order**: General → Statuses → Results → Roles → Properties → Docs → Decisions.
   Most frequently configured (Results, Roles) come before less common (Properties, Docs,
   Decisions) to minimise scrolling for typical admin workflows.
