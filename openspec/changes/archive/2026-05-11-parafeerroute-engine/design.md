# Design: parafeerroute-engine

## Architecture Overview

Parafeerroutes are `parafeerroute` OpenRegister objects. The `ParafeerRouteService` is the core routing engine: it activates steps, records parafeeracties, and advances the voorstel through its route. The voorstel carries an immutable `routeSnapshot` captured at submission time so route edits do not affect in-flight voorstellen. The admin UI allows beheerders to create and configure named routes.

```
AdminSettings.vue
└── ParafeerRoutesTab.vue (list routes, "Nieuwe route" button, delete with confirm)
    └── ParafeerRouteDialog.vue (create/edit: name, voorstelType, caseType, isDefault)
        └── ParafeerStapEditor.vue (ordered step list: add/remove/reorder steps)

VoorstelDetail.vue
├── SkipStepDialog.vue (step selector + mandatory reason, blocked for mandatory=true steps)
└── AddStepDialog.vue (step config + insertion point selector)

Backend
├── ParafeerRouteService.php (route CRUD, step activation, routing engine, override logic)
└── ParafeerRouteController.php (REST endpoints for admin CRUD and voorstel overrides)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/ParafeerRouteService.php` | Route CRUD, routeSnapshot capture, step activation (task + notification), step completion/skip/add-step, audit trail recording |
| `lib/Controller/ParafeerRouteController.php` | Authenticated REST endpoints for parafeerroute management and voorstel-level overrides |
| `src/views/settings/components/ParafeerRoutesTab.vue` | Admin settings tab: list parafeerroutes with voorstelType badge, step count, isDefault badge, edit/delete actions |
| `src/views/settings/components/ParafeerRouteDialog.vue` | Create/edit route dialog: name, voorstelType, caseType, isDefault, description, embeds ParafeerStapEditor |
| `src/views/settings/components/ParafeerStapEditor.vue` | Ordered step editor: add/remove/reorder steps, per-step fields (type, actorType, actor, mandatory) |
| `src/views/cases/components/SkipStepDialog.vue` | Skip step with mandatory reason; submission blocked for mandatory=true steps |
| `src/views/cases/components/AddStepDialog.vue` | Add ad-hoc step with insertion-point selector, step type and actor fields |
| `src/services/parafeerRouteApi.js` | Frontend API service for all parafeerroute and routing engine endpoints |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `parafeerroute` and `parafeeractie` schemas; add seed objects |
| `lib/Service/SettingsService.php` | Add config keys: `parafeerroute_schema`, `parafeeractie_schema` |
| `appinfo/routes.php` | Add parafeerroute routes (admin CRUD + voorstel action endpoints) |
| `src/views/settings/AdminSettings.vue` | Add "Parafeerroutes" tab embedding `ParafeerRoutesTab` |
| `src/views/cases/components/VoorstelDetail.vue` | Embed `SkipStepDialog` and `AddStepDialog` override controls for authorized managers |

## Data Model

Uses `parafeerroute`, `parafeeractie`, and `voorstel` entities exactly as defined in ADR-000. OpenRegister built-in fields (`id`, `uuid`, `createdAt`, `updatedAt`, `auditTrail`, `status`, etc.) are available automatically.

### parafeerroute (from ADR-000)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of the route (e.g. Collegeadvies - Omgevingsvergunning) |
| caseType | string | No | Reference to the case type this route is associated with |
| voorstelType | string | No | Voorstel type: `dt_advies`, `collegeadvies`, or `raadsvoorstel` |
| steps | array | Yes | Ordered list of `parafeerstap` objects |
| isDefault | boolean | No | Whether this is the default route for the linked caseType + voorstelType |
| description | string | No | Description of when this route should be used |

**parafeerstap sub-object (stored in `steps` array):**

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| order | integer | Yes | 1-based step position |
| type | string | Yes | `advies`, `parafering`, or `accordering` |
| actor | string | Yes | User UID, group name, or role name |
| actorType | string | Yes | `user`, `group`, or `role` |
| mandatory | boolean | Yes | Whether this step can be skipped |

**Status lifecycle for `voorstel` (relevant fields):**
```
currentStep = 0    → not yet submitted for parafering
currentStep = 1..N → in parafering (step N active)
status = geaccordeerd → all steps completed
```

### parafeeractie (from ADR-000)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | string | Yes | Reference to the voorstel (UUID) |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | string | No | `user` or `delegate` |
| onBehalfOf | string | No | Principal UID if acting as delegate |
| action | string | Yes | `parafered`, `returned`, `advised`, `skipped`, or `accorded` |
| comment | string | No | Comment or reason (mandatory for `returned` and `skipped`) |
| advice | string | No | Advisory text (for `advies` steps) |
| mandate | string | No | Mandate reference for delegate actions |

### voorstel (relevant fields from ADR-000)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| parafeerroute | string | No | Reference to the parafeerroute (pre-selected default or steller choice) |
| routeSnapshot | string | No | JSON-encoded snapshot of the steps array at submission time |
| currentStep | integer | No | Current active step number (1-based; 0 = not yet submitted) |
| returnedFromStep | integer | No | Step from which the voorstel was returned (used on resubmit) |

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/parafeer-route` | List all parafeerroutes (filter by `caseType`, `voorstelType`) |
| POST | `/api/parafeer-route` | Create new parafeerroute (admin only) |
| GET | `/api/parafeer-route/{id}` | Get single parafeerroute |
| PUT | `/api/parafeer-route/{id}` | Update parafeerroute (admin only) |
| DELETE | `/api/parafeer-route/{id}` | Delete parafeerroute (admin only; blocked if active voorstellen use it) |
| POST | `/api/parafeer-route/voorstel/{voorstelId}/start` | Start parafering: capture routeSnapshot, set currentStep=1, activate step 1 |
| POST | `/api/parafeer-route/voorstel/{voorstelId}/complete-step` | Complete current step: record parafeeractie, advance to next step |
| POST | `/api/parafeer-route/voorstel/{voorstelId}/skip-step` | Skip current or future step (manager only; mandatory reason) |
| POST | `/api/parafeer-route/voorstel/{voorstelId}/add-step` | Add ad-hoc step at insertion point (renumber subsequent steps) |

## Seed Data

The following seed objects MUST be included in `procest_register.json` under `components.objects[]`. All slugs are unique for idempotent re-import.

### parafeerroute — 4 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeerroute",
    "slug": "route-collegeadvies-omgevingsvergunning"
  },
  "name": "Collegeadvies - Omgevingsvergunning",
  "voorstelType": "collegeadvies",
  "isDefault": true,
  "description": "Standaard accorderingslijn voor collegeadviezen over omgevingsvergunningen",
  "steps": [
    {"order": 1, "type": "advies",      "actor": "juridische-dienst",   "actorType": "group", "mandatory": false},
    {"order": 2, "type": "parafering",  "actor": "teamleider-vth",      "actorType": "role",  "mandatory": true},
    {"order": 3, "type": "parafering",  "actor": "afdelingshoofd-vth",  "actorType": "role",  "mandatory": true},
    {"order": 4, "type": "accordering", "actor": "portefeuillehouder",  "actorType": "role",  "mandatory": true}
  ]
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeerroute",
    "slug": "route-collegeadvies-bestemmingsplan"
  },
  "name": "Collegeadvies - Bestemmingsplan",
  "voorstelType": "collegeadvies",
  "isDefault": false,
  "description": "Uitgebreide route voor bestemmingsplanwijzigingen met planologisch en juridisch advies",
  "steps": [
    {"order": 1, "type": "advies",      "actor": "planologisch-adviseur",         "actorType": "role",  "mandatory": true},
    {"order": 2, "type": "advies",      "actor": "juridische-dienst",             "actorType": "group", "mandatory": true},
    {"order": 3, "type": "parafering",  "actor": "teamleider-ro",                 "actorType": "role",  "mandatory": true},
    {"order": 4, "type": "parafering",  "actor": "afdelingshoofd-ro",             "actorType": "role",  "mandatory": true},
    {"order": 5, "type": "accordering", "actor": "wethouder-ruimtelijke-ordening","actorType": "role",  "mandatory": true}
  ]
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeerroute",
    "slug": "route-dt-advies-standaard"
  },
  "name": "DT-advies - Standaard",
  "voorstelType": "dt_advies",
  "isDefault": true,
  "description": "Standaard directieteam-advies: behandelaar parafering gevolgd door afdelingshoofd accordering",
  "steps": [
    {"order": 1, "type": "parafering",  "actor": "behandelaar",    "actorType": "role", "mandatory": true},
    {"order": 2, "type": "accordering", "actor": "afdelingshoofd", "actorType": "role", "mandatory": true}
  ]
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeerroute",
    "slug": "route-raadsvoorstel-groot-project"
  },
  "name": "Raadsvoorstel - Groot project",
  "voorstelType": "raadsvoorstel",
  "isDefault": true,
  "description": "Volledige route voor raadsvoorstellen: financieel, juridisch, management, gemeentesecretaris en burgemeester",
  "steps": [
    {"order": 1, "type": "advies",      "actor": "financieel-adviseur",  "actorType": "role",  "mandatory": true},
    {"order": 2, "type": "advies",      "actor": "juridische-dienst",    "actorType": "group", "mandatory": true},
    {"order": 3, "type": "parafering",  "actor": "teamleider",           "actorType": "role",  "mandatory": true},
    {"order": 4, "type": "parafering",  "actor": "afdelingshoofd",       "actorType": "role",  "mandatory": true},
    {"order": 5, "type": "accordering", "actor": "gemeentesecretaris",   "actorType": "role",  "mandatory": true},
    {"order": 6, "type": "accordering", "actor": "burgemeester",         "actorType": "role",  "mandatory": true}
  ]
}
```

### parafeeractie — 3 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap1-advies-2026-0042"
  },
  "voorstel": "voorstel-collegeadvies-0042",
  "step": 1,
  "actor": "j.devries",
  "actorType": "user",
  "action": "advised",
  "advice": "Akkoord, mits de bouwtekeningen worden bijgewerkt conform het welstandsadvies van 12 april 2026."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap2-parafering-2026-0042"
  },
  "voorstel": "voorstel-collegeadvies-0042",
  "step": 2,
  "actor": "m.bakker",
  "actorType": "user",
  "action": "parafered",
  "comment": "Geparafeerd na beoordeling welstandsadvies. Tekeningen conform bijgewerkt."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap2-geretourneerd-2026-0055"
  },
  "voorstel": "voorstel-collegeadvies-0055",
  "step": 2,
  "actor": "p.janssen",
  "actorType": "user",
  "action": "returned",
  "comment": "Financiële paragraaf ontbreekt. Kosten-batenanalyse moet worden toegevoegd voordat dit voorstel kan worden geparafeerd."
}
```

## Reuse Analysis

The following existing OpenRegister and platform capabilities are reused — no custom implementations needed for these:

| Capability | Platform Component | Reuse |
|------------|-------------------|-------|
| CRUD REST for `parafeerroute` / `parafeeractie` | `ObjectService.saveObject()` / `findObjects()` (3-arg API) | `ParafeerRouteService` delegates all persistence to `ObjectService` |
| Audit trail for route modifications | Automatic per-object audit trail (OpenRegister built-in) + explicit `auditTrail` entries on voorstel for skip/add-step | No custom audit logging needed for standard CRUD; explicit entries only for override operations |
| Task creation per step actor | `TasksController` (platform) | `ParafeerRouteService::activateStep()` calls `TasksController` to create a task per step |
| Nextcloud notifications | `NotificatieService` (platform) | Used for step activation, skip, and geaccordeerd notifications |
| Step reordering in admin UI | Manual up/down order buttons in `ParafeerStapEditor` | No third-party drag-and-drop library required |
| Store + CRUD in frontend | `createObjectStore('parafeer-route')` and `createObjectStore('parafeer-actie')` | Standard Pinia store with `relationsPlugin` |
| Relation to caseType | OpenRegister relations API | `parafeerroute.caseType` stores caseType UUID; `fetchUses` retrieves linked case type |
| Form dialog pattern | `CnFormDialog` (platform) | `ParafeerRouteDialog` and `ParafeerStapEditor` follow `CnFormDialog` composition |

**No overlap found** with existing services for parafeerroute-specific logic. The custom `ParafeerRouteService` is justified because it must orchestrate: `routeSnapshot` capture, step lifecycle state transitions, notification dispatch, task creation, and audit trail recording in coordinated sequence — this domain choreography is not provided by the platform.
