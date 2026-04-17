<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: vth-workflow-templates (Vth Workflow Templates)
     This spec extends the existing `vth-workflow-templates` capability. Do NOT define new entities or build new CRUD — reuse what `vth-workflow-templates` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Design: enforcement-lhs

## Architecture Overview

The LHS enforcement feature adds a domain service layer (`LhsMatrixService`) that encodes the national enforcement strategy matrix, a management service (`HandhavingsactieService`) that combines OpenRegister storage with LHS logic, and Vue components that surface enforcement actions both in case context and as a standalone enforcement overview.

```
HandhavingView.vue (standalone enforcement overview)
├── CnFilterBar (ernst / status / type filters — provided by platform)
├── CnDataTable (enforcement action list — provided by platform)
│   └── HandhavingsactieRow.vue (status badge, deadline indicator, LHS pill)
└── CnMassExportDialog (CSV/Excel export — provided by platform)

CaseDetail.vue
└── HandhavingsactieSection.vue (enforcement actions for the open case)
    ├── HandhavingsactieCard.vue (single action: status, deadline, interventie)
    └── LhsMatrixDialog.vue (ernst + gedrag selector with live suggestion preview)
        └── HandhavingsactieFormDialog.vue (begunstigingstermijn, bedragen, dates)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/LhsMatrixService.php` | Encodes the 4×4 LHS matrix (ernst × gedrag → interventie). Stateless pure-function service. Provides `suggest(ernst, gedrag): string` and `getMatrix(): array`. |
| `lib/Service/HandhavingsactieService.php` | Orchestrates handhavingsactie CRUD via OpenRegister `ObjectService`. Calls `LhsMatrixService` on creation; validates `overrideReason` when interventie deviates from suggestion. |
| `lib/Controller/HandhavingsactieController.php` | Thin REST controller: list, create, read, update, delete, `suggest` action. |
| `lib/BackgroundJob/HandhavingsactieDeadlineJob.php` | Daily `TimedJob`. Queries active enforcement actions where `effectueringsDatum` is within `enforcement_deadline_warning_days` (config key). Sends Nextcloud notifications via `IManager`. |
| `src/views/handhaving/HandhavingView.vue` | Standalone enforcement overview page. Uses `CnFilterBar` + `CnDataTable` + `CnMassExportDialog`. |
| `src/views/handhaving/components/HandhavingsactieSection.vue` | Embedded in `CaseDetail.vue`. Lists enforcement actions for the current case. "Actie toevoegen" button opens `LhsMatrixDialog`. |
| `src/views/handhaving/components/HandhavingsactieCard.vue` | Read-only summary card: interventie badge, ernst/gedrag pills, deadline countdown, status. |
| `src/views/handhaving/components/LhsMatrixDialog.vue` | Two-step dialog: step 1 select ernst + gedrag and preview suggested interventie; step 2 complete the full `handhavingsactie` form (begunstigingstermijn, bedragen, effectueringsDatum, optional overrideReason). |
| `src/stores/handhavingsactie.js` | Pinia store created with `createObjectStore('handhavingsacties')`. |
| `src/services/handhavingsactieApi.js` | Frontend API service wrapping all `HandhavingsactieController` endpoints including the `suggest` action. |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Verify `handhavingsactie` schema is present; add seed objects (see Seed Data section below). |
| `appinfo/routes.php` | Add `handhavingsacties` resource routes + `POST /api/handhavingsacties/suggest`. |
| `src/views/cases/CaseDetail.vue` | Import and render `HandhavingsactieSection.vue` in the case detail sidebar/body. |
| `src/router/index.js` | Add `/handhaving` route pointing to `HandhavingView.vue`. |
| `src/views/navigation/AppNavigation.vue` | Add "Handhaving" navigation item with enforcement icon. |

## Design Decisions

### DD-01: LHS Matrix as Pure PHP Array — No DB Storage

**Decision**: Encode the LHS matrix as a static two-dimensional PHP array in `LhsMatrixService`. Do not store it in OpenRegister or a database table.

**Rationale**: The LHS matrix is a published national standard (Ministerie van Justitie en Veiligheid) that changes only with new policy publications. Storing it as static code ensures it is versioned with the application and does not require a migration when it changes. Municipalities cannot legally customise the matrix.

### DD-02: Override Requires Documented Reason

**Decision**: `HandhavingsactieService::save()` throws a validation exception if the submitted `interventie` differs from `LhsMatrixService::suggest(ernst, gedrag)` and `overrideReason` is null or empty.

**Rationale**: LHS compliance requires that deviations from the matrix are documented. Enforcing this at the service layer — not the UI layer — means no API path can bypass the requirement.

### DD-03: Suggest Endpoint — Stateless Preview

**Decision**: Expose `POST /api/handhavingsacties/suggest` that accepts `{ernst, gedrag}` and returns `{interventie}` without creating any object.

**Rationale**: The `LhsMatrixDialog.vue` needs to show a live preview as the inspector selects ernst and gedrag. A lightweight stateless endpoint avoids partial object creation and keeps the dialog responsive.

### DD-04: Deadline Notifications via Background Job, Not Webhooks

**Decision**: Use a daily `TimedJob` for deadline detection rather than webhooks or event listeners.

**Rationale**: OpenRegister does not emit date-relative events. A daily job querying `effectueringsDatum <= now() + warning_days` is sufficient for the use case and avoids complexity. The warning window is configurable via `IAppConfig` key `enforcement_deadline_warning_days` (default 7).

## LHS Matrix Reference

The LHS matrix (Landelijke Handhavingsstrategie, 2022 revision) maps ernst × gedrag to an intervention category:

| | Goedwillend | Onachtzaam | Calculerend | Opzettelijk |
|---|---|---|---|---|
| **Gering** | Aanspreken / Adviseren | Waarschuwing | Last onder dwangsom | Last onder dwangsom |
| **Matig** | Waarschuwing | Last onder dwangsom | Last onder dwangsom | Last onder bestuursdwang |
| **Ernstig** | Last onder dwangsom | Last onder bestuursdwang | Last onder bestuursdwang | Bestuurlijke boete / Intrekking |
| **Zeer ernstig** | Last onder bestuursdwang | Bestuurlijke boete / Intrekking | Bestuurlijke boete / Intrekking | Strafrechtelijk optreden |

Valid values for `ernst`: `gering`, `matig`, `ernstig`, `zeer_ernstig`
Valid values for `gedrag`: `goedwillend`, `onachtzaam`, `calculerend`, `opzettelijk`
Valid intervention slugs: `aanspreken_adviseren`, `waarschuwing`, `last_onder_dwangsom`, `last_onder_bestuursdwang`, `bestuurlijke_boete_intrekking`, `strafrechtelijk_optreden`

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/handhavingsacties` | List enforcement actions (filterable by `case`, `ernst`, `status`, `type`) |
| POST | `/api/handhavingsacties` | Create enforcement action |
| GET | `/api/handhavingsacties/{id}` | Get enforcement action |
| PUT | `/api/handhavingsacties/{id}` | Update enforcement action |
| DELETE | `/api/handhavingsacties/{id}` | Delete enforcement action |
| POST | `/api/handhavingsacties/suggest` | Get LHS intervention suggestion for ernst + gedrag (stateless, no object created) |

## Reuse Analysis

This section documents existing platform capabilities leveraged by this change, per ADR-012-deduplication requirements.

| Capability | Platform Component | Usage |
|---|---|---|
| Object CRUD | `ObjectService::saveObject()`, `deleteObject()`, `getObject()` | All handhavingsactie persistence goes through `HandhavingsactieService` → `ObjectService` |
| List + filter | `CnDataTable` + `CnFilterBar` + `useListView` composable | `HandhavingView.vue` and `HandhavingsactieSection.vue` list views |
| Search | `IndexService` (full-text) | Full-text search across handhavingsactie fields — no custom search endpoint |
| Export | `ExportService` + `CnMassExportDialog` | CSV/Excel export from `HandhavingView.vue` — no custom export controller |
| Notifications | `OCP\Notification\IManager` | Deadline notifications from `HandhavingsactieDeadlineJob` |
| Audit trail | OpenRegister built-in audit trail | All changes to handhavingsactie objects are automatically audited |
| Form generation | `CnFormDialog` (schema-driven) | Create/edit dialog auto-generated from handhavingsactie schema; `LhsMatrixDialog` wraps this with the two-step LHS selection |
| Pinia store | `createObjectStore('handhavingsacties')` | Standard CRUD store, no custom state management |

**No functional overlap found** with existing Procest services. `LhsMatrixService` is a new domain-specific lookup service with no equivalent in OpenRegister or existing app code.

## Seed Data

The following seed objects are added to `procest_register.json` under `components.objects[]` to support dev/test. All values are fictional but realistic Dutch municipal enforcement scenarios.

### handhavingsactie Seed Objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "handhavingsactie",
    "slug": "handhavingsactie-overtreding-nachtsluis-amsterdam"
  },
  "case": "case-bouw-overtredingen-2026-0041",
  "type": "last_onder_dwangsom",
  "ernst": "matig",
  "gedrag": "calculerend",
  "interventie": "last_onder_dwangsom",
  "begunstigingstermijn": 28,
  "dwangsomBedrag": 2500.00,
  "dwangsomMaximaal": 25000.00,
  "effectueringsDatum": "2026-05-15",
  "status": "actief",
  "overrideReason": null
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "handhavingsactie",
    "slug": "handhavingsactie-brand-overtreding-rotterdam"
  },
  "case": "case-brandveiligheid-2026-0017",
  "type": "last_onder_bestuursdwang",
  "ernst": "ernstig",
  "gedrag": "onachtzaam",
  "interventie": "last_onder_bestuursdwang",
  "begunstigingstermijn": 14,
  "dwangsomBedrag": null,
  "dwangsomMaximaal": null,
  "effectueringsDatum": "2026-04-30",
  "status": "actief",
  "overrideReason": null
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "handhavingsactie",
    "slug": "handhavingsactie-milieu-overtreding-utrecht"
  },
  "case": "case-milieu-2026-0089",
  "type": "waarschuwing",
  "ernst": "gering",
  "gedrag": "goedwillend",
  "interventie": "aanspreken_adviseren",
  "begunstigingstermijn": null,
  "dwangsomBedrag": null,
  "dwangsomMaximaal": null,
  "effectueringsDatum": null,
  "status": "afgerond",
  "overrideReason": "Overtreder heeft direct actie ondernomen; escalatie niet proportioneel. Mondelinge waarschuwing volstaat conform gemeentelijk handhavingsbeleid."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "handhavingsactie",
    "slug": "handhavingsactie-reclame-overtreding-den-haag"
  },
  "case": "case-reclame-2026-0054",
  "type": "bestuurlijke_boete_intrekking",
  "ernst": "zeer_ernstig",
  "gedrag": "opzettelijk",
  "interventie": "strafrechtelijk_optreden",
  "begunstigingstermijn": null,
  "dwangsomBedrag": null,
  "dwangsomMaximaal": null,
  "effectueringsDatum": "2026-04-22",
  "status": "actief",
  "overrideReason": "Zaak doorgestuurd naar OM voor strafrechtelijk traject; parallel bestuurlijke boete opgelegd conform duaal spoor."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "handhavingsactie",
    "slug": "handhavingsactie-sloop-overtreding-eindhoven"
  },
  "case": "case-sloop-2026-0033",
  "type": "last_onder_dwangsom",
  "ernst": "matig",
  "gedrag": "onachtzaam",
  "interventie": "last_onder_dwangsom",
  "begunstigingstermijn": 21,
  "dwangsomBedrag": 1500.00,
  "dwangsomMaximaal": 15000.00,
  "effectueringsDatum": "2026-05-08",
  "status": "opgeschort",
  "overrideReason": null
}
```
