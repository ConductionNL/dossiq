<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Design: bezwaar-lifecycle

## Architecture Overview

Deadline enforcement lives in a new `BezwaarDeadlineService` that is called from:
1. The `objection` creation path — sets `case.deadline` automatically.
2. The `BezwaarDeadlineController` — handles verdaging and opschorting REST calls.
3. The `BezwaarDeadlineJob` — daily timed job that scans open bezwaar cases and dispatches Nextcloud notifications.

The existing frontend `bezwaar.js` store and `DeadlineIndicator.vue` / `DeadlinePanel.vue` components are NOT changed — they already render correctly once `case.deadline` is populated on the backend.

```
BezwaarDeadlineController (POST extend / POST suspend / POST resume / GET overdue)
        │
        └── BezwaarDeadlineService
                ├── calculateDeadline(receivedDate, processingDeadline): string
                ├── applyExtension(caseId, reason): void
                ├── suspendDeadline(caseId, reason): void
                ├── resumeDeadline(caseId): void
                └── getOverdueCases(limit): array

BezwaarDeadlineJob (daily TimedJob)
        └── BezwaarDeadlineService::getApproachingDeadlines(withinDays: 7): array
        └── INotificationManager::notify(handler, case)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/BezwaarDeadlineService.php` | Deadline calculation, extension, suspension, overdue query |
| `lib/Controller/BezwaarDeadlineController.php` | REST API for deadline actions and overdue list |
| `lib/BackgroundJob/BezwaarDeadlineJob.php` | Daily job — Nextcloud notifications for at-risk and overdue bezwaar cases |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/bezwaar_seed_data.json` | Add 5 example bezwaar case instances with objections and varied deadline states |
| `lib/Service/SeedDataService.php` | Add `seedBezwaarCaseInstances()` method to insert example case objects |
| `lib/Repair/SeedBezwaarBeroepData.php` | Call `seedBezwaarCaseInstances()` after case-type seed completes |
| `appinfo/routes.php` | Add bezwaar deadline routes |

## Design Decisions

### DD-01: Deadline Stored on `case.deadline`

`case.deadline` (already defined in ADR-000) is the single source of truth. The backend service writes it; the frontend reads it. No new field is needed.

**Rationale:** Consistent with how other case types track deadlines. Dashboard queries can filter by `case.deadline < now` without bezwaar-specific logic.

### DD-02: Suspension via `caseProperty`

Suspension start/end dates are stored as `caseProperty` records with `propertyDefinition` slugs `bezwaar_suspension_start` and `bezwaar_suspension_end`. The service excludes suspended calendar days when recalculating the effective deadline.

**Rationale:** No new schema needed. `caseProperty` is the existing extension point for case-specific data. Audit trail is automatic via OpenRegister.

### DD-03: Extension Reason in Case Notes

When verdaging is applied the service appends a timestamped note to the case's `notes` collection via `ObjectService`. This satisfies the Awb motiveringsplicht without a new entity.

**Rationale:** Notes are searchable and auditable via existing OpenRegister infrastructure.

## API Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| POST | `/api/bezwaar/{caseId}/deadline/extend` | Admin | Apply verdaging (extension). Body: `{ reason: string }` |
| POST | `/api/bezwaar/{caseId}/deadline/suspend` | Admin | Start opschorting. Body: `{ reason: string, startDate: string }` |
| POST | `/api/bezwaar/{caseId}/deadline/resume` | Admin | End opschorting and recalculate deadline. Body: `{ endDate: string }` |
| GET | `/api/bezwaar/overdue` | Authenticated | List overdue and at-risk cases. Query: `withinDays` (default 7), `limit`, `page` |

## Reuse Analysis

| Capability | OpenRegister / Platform service | Note |
|---|---|---|
| Case CRUD | `ObjectService::findObject / saveObject` | Used directly — no custom mapper |
| Case list filtering | `ObjectService::findObjects` with `deadline < today` filter | Standard filter parameter |
| Audit trail for deadline changes | OpenRegister built-in audit trail on `case` object | No custom implementation needed |
| Case notes for extension reason | OpenRegister `notes` relation on case | Appended via ObjectService |
| Nextcloud notifications | `OCP\Notification\IManager` | Standard OCP interface |
| Background job scheduling | `OCP\BackgroundJob\TimedJob` | Standard OCP interface |
| Case property storage | `caseProperty` schema (already in register) | No new schema needed |

No duplication with existing `AppointmentService`, `MilestoneService`, or `ParaferingService` — this is the first backend deadline enforcement service for bezwaar.

## Seed Data

Seed objects are added to `bezwaar_seed_data.json` alongside existing case-type definitions. They are loaded by the extended `SeedDataService::seedBezwaarCaseInstances()` method during the repair step.

**Note:** Per ADR (OpenRegister ImportHandler limitation), seed data is flat object properties only. Related items (files, notes, tasks) are not included.

### caseType seed (already exists)

Identifier `bezwaar` with `processingDeadline: "P6W"`, `extensionAllowed: true`, `extensionPeriod: "P6W"`, `suspensionAllowed: true` — already present in `bezwaar_seed_data.json`.

### case seed objects (new — 5 instances)

| Slug | Title | Deadline | State |
|------|-------|----------|-------|
| `bezwaar-case-overdue-001` | Bezwaar Omgevingsvergunning Hofstraat 12 | 2026-03-15 | Overdue (32 days past) |
| `bezwaar-case-atrisk-001` | Bezwaar APV Marktvergunning Bakker BV | 2026-04-20 | At-risk (4 days remaining) |
| `bezwaar-case-ontrack-001` | Bezwaar WOZ aanslag 2026 Van der Berg | 2026-05-28 | On-track (42 days remaining) |
| `bezwaar-case-extended-001` | Bezwaar Kapvergunning Lindelaan 7 | 2026-05-01 | Extended (extensionCount: 1) |
| `bezwaar-case-suspended-001` | Bezwaar Terrasvergunning Café De Zon | 2026-06-15 | Suspended (waiting for info) |

### objection seed objects (new — 5 instances, one per case above)

```json
{
  "@self": { "register": "procest", "schema": "objection", "slug": "objection-hofstraat-001" },
  "grounds": "Het besluit tot weigering van de omgevingsvergunning is onvoldoende gemotiveerd. Verzoeker stelt dat het bouwplan voldoet aan het bestemmingsplan.",
  "requestedRelief": "Heroverweging en alsnog verlening van de omgevingsvergunning",
  "receivedDate": "2026-02-01",
  "receivedChannel": "post",
  "isTimely": true,
  "timelinessAssessment": "Bezwaar ontvangen binnen 6 weken na bekendmaking besluit van 2026-01-05"
}
```

```json
{
  "@self": { "register": "procest", "schema": "objection", "slug": "objection-bakker-001" },
  "grounds": "De weigering van de marktvergunning is gebaseerd op onjuiste feiten. Bakker BV heeft aantoonbaar voldaan aan alle gestelde eisen.",
  "requestedRelief": "Toekenning van de gevraagde marktvergunning",
  "receivedDate": "2026-03-11",
  "receivedChannel": "digitaal",
  "isTimely": true,
  "timelinessAssessment": "Bezwaar ontvangen binnen termijn"
}
```

```json
{
  "@self": { "register": "procest", "schema": "objection", "slug": "objection-woz-001" },
  "grounds": "De WOZ-waarde van € 385.000 is te hoog vastgesteld. Vergelijkbare woningen in de straat zijn aangeslagen voor € 340.000 tot € 360.000.",
  "requestedRelief": "Verlaging WOZ-waarde naar € 355.000",
  "receivedDate": "2026-04-05",
  "receivedChannel": "digitaal",
  "isTimely": true,
  "timelinessAssessment": "Bezwaar tijdig ingediend"
}
```

```json
{
  "@self": { "register": "procest", "schema": "objection", "slug": "objection-kap-001" },
  "grounds": "De kapvergunning is ten onrechte geweigerd. De betreffende boom is ziek en vormt een veiligheidsrisico.",
  "requestedRelief": "Alsnog verlening van de kapvergunning",
  "receivedDate": "2026-02-19",
  "receivedChannel": "post",
  "isTimely": true,
  "timelinessAssessment": "Bezwaar tijdig ontvangen"
}
```

```json
{
  "@self": { "register": "procest", "schema": "objection", "slug": "objection-terras-001" },
  "grounds": "Het terrasverbod is disproportioneel. Café De Zon heeft geen klachten ontvangen en voldoet aan alle geluidsnormen.",
  "requestedRelief": "Intrekking van het terrasverbod en herstel van de terrasvergunning",
  "receivedDate": "2026-03-01",
  "receivedChannel": "digitaal",
  "isTimely": true,
  "timelinessAssessment": "Tijdig ingediend"
}
```
