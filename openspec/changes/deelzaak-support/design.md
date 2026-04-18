<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Design: Deelzaak Support

## Architecture

Deelzaak support builds entirely on the existing `case` and `caseType` schemas — both already carry the necessary fields (`parentCase`, `relatedCases`, `subCaseTypes`). No new OpenRegister schemas are required.

Sub-cases are queried via OpenRegister's filter API (`parentCase={uuid}`). The parent case is resolved via a single additional object fetch when a sub-case detail is loaded for breadcrumb rendering. The case list sub-case count is batch-loaded per page to avoid N+1 queries.

```
CaseDetail.vue
├── ParentCaseBreadcrumb (inline — rendered when case.parentCase != null)
├── SubCasesSection.vue
│   ├── roll-up header ("Sub-cases (X/Y voltooid)")
│   ├── compact table: title, status, behandelaar, deadline
│   └── "Deelzaak aanmaken" button → CaseCreateDialog (parentCase context)
└── CaseCreateDialog.vue (extended: parentCase prop, filtered caseType dropdown)

CaseList.vue
└── sub-case count badge per row (batch-loaded)

Settings: CaseTypeDetail.vue
└── SubCaseTypesTab.vue (configure allowed deelzaaktypen)
```

### Key Decisions

**D1 – Query sub-cases via OpenRegister filter, not stored array**
Query sub-cases with `GET /api/objects/{register}/{schema}?parentCase={uuid}`. Avoids dual-write problem of maintaining a `subCases` array on the parent.

**D2 – SubCasesSection as a standalone component in CaseDetail**
Follows the pattern of existing section components (`ParticipantsSection.vue`, `ResultSection.vue`). Always visible in main content — not a tab — because sub-case status is core case context.

**D3 – Extend CaseCreateDialog with parentCase prop**
When the `parentCase` prop is set: title changes to "Deelzaak aanmaken", the caseType dropdown filters to `subCaseTypes`, and `parentCase` is auto-set on submit (hidden from user).

**D4 – Single-level nesting enforced in UI**
ZGW rule zrc-013c prohibits deelzaak of a deelzaak. The "Deelzaak aanmaken" button is hidden when the current case is itself a sub-case (`case.parentCase != null`). Service-layer validation mirrors this.

**D5 – Sub-case count batch load for case list**
On list page render, batch-load sub-case counts with a single OpenRegister query using `parentCase IN [uuid1, uuid2, ...]`. Count is derived from the result set — no extra schema field.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `src/views/cases/components/SubCasesSection.vue` | Sub-cases list, roll-up indicator, "Deelzaak aanmaken" trigger |
| `src/views/settings/tabs/SubCaseTypesTab.vue` | Admin tab to configure allowed deelzaaktypen per caseType |

### Modified Files

| File | Changes |
|------|---------|
| `src/views/cases/CaseDetail.vue` | Add `SubCasesSection`, add parent case breadcrumb, load parentCase on mount when `case.parentCase` is set |
| `src/views/cases/CaseCreateDialog.vue` | Accept `parentCase` and `parentCaseType` props; filter caseType dropdown to subCaseTypes; auto-set parentCase on submit; change title |
| `src/views/cases/CaseList.vue` | Add sub-case count badge column; batch-load counts on page load |
| `src/views/settings/CaseTypeDetail.vue` | Add SubCaseTypesTab |
| `src/store/modules/caseStore.js` | Add `fetchSubCases(parentUuid)`, `fetchParentCase(uuid)`, `subCases` state, `parentCase` state |

## Data Model

No new schemas. Uses existing entities from ADR-000:

### case (existing — relevant fields)

| Field | Type | Notes |
|-------|------|-------|
| `parentCase` | string (UUID ref) | Set on sub-cases; null on top-level cases |
| `relatedCases` | string (JSON array) | Optional lateral case links |
| `endDate` | string | Set when case is closed — used for roll-up completion check |

### caseType (existing — relevant fields)

| Field | Type | Notes |
|-------|------|-------|
| `subCaseTypes` | array | UUIDs of caseType objects allowed as deelzaak for this type |

## Seed Data

Seed data extends the existing base register objects. The following objects illustrate parent-child case relationships. All data is fictional but realistic.

### caseType objects

```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "omgevingsvergunning-type" },
  "title": "Omgevingsvergunning",
  "description": "Aanvraag omgevingsvergunning voor bouwen, slopen of gebruik",
  "processingDeadline": "P8W",
  "extensionAllowed": true,
  "extensionPeriod": "P6W",
  "subCaseTypes": ["bouwtoezicht-type", "milieu-type"]
}
```

```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "bouwtoezicht-type" },
  "title": "Bouwtoezicht",
  "description": "Toezicht op naleving van de bouwvergunning tijdens uitvoering",
  "processingDeadline": "P4W",
  "subCaseTypes": []
}
```

```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "milieu-type" },
  "title": "Milieuadvies",
  "description": "Advies omtrent milieuaspecten bij omgevingsvergunning",
  "processingDeadline": "P3W",
  "subCaseTypes": []
}
```

### case objects

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "hoofdzaak-omgverg-keizersgracht" },
  "title": "Omgevingsvergunning Keizersgracht 100, Amsterdam",
  "identifier": "2026-0042",
  "caseType": "omgevingsvergunning-type",
  "status": "in-behandeling",
  "assignee": "j.bakker",
  "priority": "normaal",
  "startDate": "2026-04-01",
  "deadline": "2026-05-27",
  "parentCase": null,
  "relatedCases": "[]"
}
```

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "deelzaak-bouwtoezicht-keizersgracht" },
  "title": "Bouwtoezicht Keizersgracht 100, Amsterdam",
  "identifier": "2026-0043",
  "caseType": "bouwtoezicht-type",
  "status": "ontvangen",
  "assignee": "m.visser",
  "priority": "normaal",
  "startDate": "2026-04-03",
  "deadline": "2026-05-01",
  "parentCase": "hoofdzaak-omgverg-keizersgracht"
}
```

```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "deelzaak-milieu-keizersgracht" },
  "title": "Milieuadvies Keizersgracht 100, Amsterdam",
  "identifier": "2026-0044",
  "caseType": "milieu-type",
  "status": "in-behandeling",
  "assignee": "p.de.vries",
  "priority": "hoog",
  "startDate": "2026-04-03",
  "deadline": "2026-04-24",
  "parentCase": "hoofdzaak-omgverg-keizersgracht",
  "endDate": "2026-04-15"
}
```

## Reuse Analysis

This change leverages the following existing OpenRegister and platform capabilities without rebuilding them:

| Capability | Provided by | Usage |
|-----------|-------------|-------|
| Case CRUD | `ObjectService.saveObject()` / `deleteObject()` | Sub-case creation and orphan cleanup on parent deletion |
| Filtering by field | OpenRegister REST filter API (`?parentCase=uuid`) | Fetching sub-cases for a parent |
| List with pagination | `ObjectService.findAll()` + `CnDataTable` | Sub-cases compact table in SubCasesSection |
| Schema-driven form | `CnFormDialog` / `CaseCreateDialog` | Extended for parentCase context |
| Object store | `useObjectStore()` via @conduction/nextcloud-vue | Direct CRUD and filtering on case/caseType objects |
| Audit trail | Automatic via OpenRegister | All sub-case mutations tracked without custom code |
| Notifications | `NotificationService` | Cross-case handler notification when deelzaak completes |

No new backend services or controllers are required. All data operations go through the existing OpenRegister REST API via `useObjectStore()`, following the project's established patterns. Sub-case queries use OpenRegister's built-in filter API with `?parentCase={uuid}` parameters.
