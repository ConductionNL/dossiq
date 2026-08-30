# Design: parafering-actions

## Architecture Overview

Parafering actions are `parafeeractie` OpenRegister objects — immutable records of each actor's decision on a voorstel step. The `ParafeerActieService` records actions, enforces per-step authorization, triggers step advancement via the existing `ParafeerRouteService`, and applies a PDF signature annotation to the voorstel document when an `accordering` step is completed. The actor-facing UI is a step-type-aware `ParafeerActieDialog` embedded in the voorstel detail view, alongside a read-only `ParafeerActieTimeline` showing all recorded actions.

```
VoorstelDetail.vue
├── ParafeerActieDialog.vue   (take action: adviseren / paraferen / accorderen / terugsturen)
│   └── DelegateSelectorField.vue (namens / on-behalf-of selector, shown when mandates exist)
└── ParafeerActieTimeline.vue (read-only chronological action history)

Backend
├── ParafeerActieService.php  (record action, per-step auth, route advancement, PDF signing)
└── ParafeerActieController.php (REST: POST /api/parafeer-actie, GET /api/parafeer-actie)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/ParafeerActieService.php` | Record parafeeractie (validate actor, persist, advance route, apply PDF signature for accordering) |
| `lib/Controller/ParafeerActieController.php` | `POST /api/parafeer-actie` (record action) + `GET /api/parafeer-actie` (list by voorstel) |
| `src/views/cases/components/ParafeerActieDialog.vue` | Step-type-aware action dialog for adviseren, paraferen, accorderen, terugsturen |
| `src/views/cases/components/DelegateSelectorField.vue` | "Namens" user picker shown when actor has configured mandates; populates `onBehalfOf` |
| `src/views/cases/components/ParafeerActieTimeline.vue` | Chronological action history list using `CnTimelineStages` |
| `src/services/parafeerActieApi.js` | Frontend API service for `POST /api/parafeer-actie` and `GET /api/parafeer-actie` |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add 5 Dutch `parafeeractie` seed objects under `components.objects[]` |
| `appinfo/routes.php` | Add `parafeer-actie` routes (POST + GET) |
| `src/store/store.js` | Register `parafeer-actie` entity type via `createObjectStore` (if not already registered by parafeerroute-engine; verify first) |
| `src/views/cases/components/VoorstelDetail.vue` | Embed `ParafeerActieDialog` (shown when current user is the active step actor) and `ParafeerActieTimeline` |

## Data Model

Uses `parafeeractie` and `voorstel` entities exactly as defined in ADR-000. OpenRegister built-in fields (`id`, `uuid`, `createdAt`, `updatedAt`, `auditTrail`, etc.) are available automatically.

### parafeeractie (from ADR-000)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | string | Yes | Reference to the voorstel (UUID) |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | string | No | `user` (direct) or `delegate` (acting on behalf of another) |
| onBehalfOf | string | No | Nextcloud user UID of the principal (when actorType = `delegate`) |
| action | string | Yes | `parafered`, `returned`, `advised`, `skipped`, or `accorded` |
| comment | string | No | Comment or reason (mandatory for `returned` and `skipped`) |
| advice | string | No | Advisory text (for `advies` steps) |
| mandate | string | No | Mandate reference (for delegate actions) |

**Allowed `action` values per step type:**

| Step type | Allowed actions |
|-----------|----------------|
| `advies` | `advised`, `returned` |
| `parafering` | `parafered`, `returned` |
| `accordering` | `accorded`, `returned` |

### voorstel (relevant fields from ADR-000)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| status | string | Yes | `concept`, `in_parafering`, `geaccordeerd`, `teruggestuurd`, etc. |
| currentStep | integer | No | Current active step (1-based; advanced after each completed action) |
| routeSnapshot | string | No | JSON-encoded frozen copy of the parafeerroute steps |
| returnedFromStep | integer | No | Step from which the voorstel was returned (set on `teruggestuurd`) |
| document | string | No | Nextcloud file ID of the primary voorstel document (target for PDF signature) |
| steller | string | Yes | Nextcloud user UID of the creator (recipient of teruggestuurd notifications) |

## API Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| POST | `/api/parafeer-actie` | `NoAdminRequired` + per-object actor check | Record a parafeeractie for the voorstel's current step |
| GET | `/api/parafeer-actie` | `NoAdminRequired` | List all parafeeracties for a voorstel (`?voorstel={uuid}`) |

**POST `/api/parafeer-actie` request body:**

```json
{
  "voorstel": "uuid-of-voorstel",
  "action": "parafered | advised | accorded | returned",
  "comment": "optional — mandatory when action = returned",
  "advice": "advisory text — for advies steps only",
  "onBehalfOf": "optional user UID — for delegate actions",
  "mandate": "optional mandate reference"
}
```

Authorization logic (enforced in `ParafeerActieService`):
- Fetch voorstel → decode `routeSnapshot` → get step at `currentStep`
- The logged-in user MUST match the step's `actor` OR `onBehalfOf` must be the step actor and the logged-in user must hold a configured mandate
- Otherwise throw `OCSForbiddenException` with static message `'Not authorized for this parafering step'`
- Identity derived from `IUserSession` — NEVER from request body

## Seed Data

The following seed objects MUST be included in `procest_register.json` under `components.objects[]`. All slugs are unique for idempotent re-import.

### parafeeractie — 5 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap1-advies-0042"
  },
  "voorstel": "voorstel-collegeadvies-0042",
  "step": 1,
  "actor": "j.devries",
  "actorType": "user",
  "action": "advised",
  "advice": "Akkoord met de aanvraag. Juridisch geen bezwaar. Aanbeveling: stel voorwaarden aan akoestisch onderzoek conform het bestemmingsplan Centrum 2022, art. 3.4."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap2-parafering-0042"
  },
  "voorstel": "voorstel-collegeadvies-0042",
  "step": 2,
  "actor": "m.bakker",
  "actorType": "user",
  "action": "parafered",
  "comment": "Geparafeerd. Juridisch advies verwerkt. Akoestisch onderzoek als voorwaarde opgenomen in de beschikking."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap3-accordering-0042"
  },
  "voorstel": "voorstel-collegeadvies-0042",
  "step": 3,
  "actor": "h.vanderberg",
  "actorType": "user",
  "action": "accorded",
  "comment": "Geaccordeerd namens de afdeling VTH."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap2-teruggestuurd-0055"
  },
  "voorstel": "voorstel-collegeadvies-0055",
  "step": 2,
  "actor": "p.janssen",
  "actorType": "user",
  "action": "returned",
  "comment": "Financiële paragraaf ontbreekt. De raming voor de sloopwerkzaamheden en de benodigde toezichtsuren moeten worden toegevoegd voordat dit voorstel kan worden geparafeerd."
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "parafeeractie",
    "slug": "parafeeractie-stap1-advies-delegate-0071"
  },
  "voorstel": "voorstel-dt-advies-0071",
  "step": 1,
  "actor": "s.devos",
  "actorType": "delegate",
  "onBehalfOf": "k.vermeulen",
  "action": "advised",
  "advice": "Namens K. Vermeulen (met mandaat DT-002/2026): geen bezwaar vanuit financieel perspectief. De begroting is sluitend en valt binnen de kaders van de kadernota 2026.",
  "mandate": "DT-002/2026"
}
```

## Reuse Analysis

The following existing OpenRegister and platform capabilities are reused — no custom implementations needed for these:

| Capability | Platform Component | Reuse |
|------------|-------------------|-------|
| `parafeeractie` persistence | `ObjectService.saveObject()` (3-arg API) | `ParafeerActieService` delegates all storage to `ObjectService` |
| Step advancement after action | `ParafeerRouteService::completeStep()` (from parafeerroute-engine) | Called after successful action recording — no routing logic duplicated |
| Nextcloud notifications | `NotificatieService` (platform) | Used for teruggestuurd (to steller) and geaccordeerd (final step) events |
| Audit trail | Automatic per-object audit trail (OpenRegister built-in) | Every `parafeeractie` save produces an immutable audit entry |
| Timeline visualization | `CnTimelineStages` component | `ParafeerActieTimeline` uses this for chronological event rendering |
| File access for PDF signing | `FileService` (platform, Nextcloud) | Used to read + write the voorstel's Nextcloud document file |
| CRUD store | `createObjectStore('parafeer-actie')` (if not registered in parafeerroute-engine) | Standard Pinia store — verify first to avoid double registration |
| Per-object RBAC | `IUserSession` + manual actor check in service | No custom RBAC system — derive identity from session, check against step actor |

**No overlap found** with existing services for parafeeractie-specific logic. The custom `ParafeerActieService` is justified because it must coordinate: per-step actor authorization, action validation (allowed action per step type), parafeeractie recording, route advancement via `ParafeerRouteService`, teruggestuurd status transition, and PDF signature annotation — a multi-step domain choreography not provided by the platform.
