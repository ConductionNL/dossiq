# Design: parafering-dashboard

## Architecture Overview

The parafering dashboard reads `voorstel` and `parafeeractie` objects from OpenRegister via the existing REST API and presents them in two surfaces: a secretariaat dashboard page (`VoorstellenList.vue`) at `/voorstellen` and a personal inbox section (`ParafeerInbox.vue`) embedded in `MyWork.vue`. The only new backend component is `ParafeerHerinneringService` and its controller, which sends a Nextcloud notification to the current step actor and logs the reminder in the parafering audit trail. All other data access reuses the OpenRegister CRUD API and previously built services.

```
Sidebar navigation
└── "Voorstellen" → /voorstellen

VoorstellenList.vue (secretariaat dashboard)
├── VoorstellenRow.vue        (one row per voorstel: status, step, actor, days waiting, progress)
│   └── HerinneringButton.vue (shows for overdue rows; POST /api/parafeer-herinnering)
└── (empty state: "Geen actieve voorstellen")

MyWork.vue
└── ParafeerInbox.vue         ("Ter parafering" section for the current user)
    └── ParafeerActieDialog   (reused from parafering-actions for quick action taking)

Backend
├── ParafeerHerinneringService.php    (send notification, log audit reminder)
└── ParafeerHerinneringController.php (POST /api/parafeer-herinnering)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/ParafeerHerinneringService.php` | Send Nextcloud reminder notification to current step actor; log reminder in audit trail |
| `lib/Controller/ParafeerHerinneringController.php` | `POST /api/parafeer-herinnering` — secretariaat-only reminder endpoint |
| `src/views/voorstellen/VoorstellenList.vue` | Secretariaat parafering dashboard — sortable table of all `in_parafering` voorstellen |
| `src/views/voorstellen/components/VoorstellenRow.vue` | Single row for a voorstel in the dashboard table: onderwerp, step, actor, days waiting, progress badge, overdue indicator, reminder button |
| `src/views/voorstellen/components/HerinneringButton.vue` | "Herinnering sturen" button shown on overdue rows; posts to `/api/parafeer-herinnering` |
| `src/views/MyWork/components/ParafeerInbox.vue` | "Ter parafering" section in MyWork view — compact list of voorstellen awaiting the current user's action |
| `src/services/herinneringApi.js` | Frontend API service for `POST /api/parafeer-herinnering` |

### Modified Files

| File | Changes |
|------|---------|
| `appinfo/routes.php` | Add `parafeer-herinnering` route (POST) |
| `src/router/router.js` | Add `/voorstellen` route mapping to `VoorstellenList.vue` |
| `src/navigation/Navigation.vue` (or equivalent sidebar component) | Add "Voorstellen" navigation item pointing to `/voorstellen` |
| `src/views/MyWork/MyWork.vue` | Import and embed `ParafeerInbox` in the MyWork view |
| `lib/Settings/procest_register.json` | Add 4 Dutch `voorstel` seed objects under `components.objects[]` |

## Data Model

Uses `voorstel` and `parafeeractie` entities exactly as defined in ADR-000. No new entities are introduced. OpenRegister built-in fields (`id`, `uuid`, `createdAt`, `updatedAt`, `auditTrail`, etc.) are available automatically.

### voorstel (from ADR-000 — fields relevant to dashboard)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the parent case |
| type | string | Yes | Type: `DT-advies`, `Collegeadvies`, or `Raadsvoorstel` |
| onderwerp | string | Yes | Subject of the voorstel (displayed in dashboard) |
| steller | string | Yes | Nextcloud user UID of the creator |
| afdeling | string | No | Department of the steller |
| portefeuillehouder | string | No | Nextcloud user UID of the responsible portfolio holder |
| status | string | Yes | Current lifecycle status — dashboard filters on `in_parafering` |
| parafeerroute | string | No | Reference to the parafeerroute |
| routeSnapshot | string | No | JSON-encoded snapshot of steps — used to derive current step name and total step count |
| currentStep | integer | No | Current active step (1-based) — used to compute "stap X/Y" progress |

**Derived display values computed on the frontend (not stored):**
- **Current step name**: `routeSnapshot[currentStep - 1].label`
- **Waiting actor**: `routeSnapshot[currentStep - 1].actor`
- **Days waiting**: `Math.floor((now - lastParafeeractieCreatedAt) / 86400000)`
- **Progress**: `"stap ${currentStep}/${routeSnapshot.length}"`

### parafeeractie (from ADR-000 — used for days-waiting calculation)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | string | Yes | Reference to the voorstel (UUID) |
| step | integer | Yes | Step number the action was recorded at |
| actor | string | Yes | Nextcloud user UID who performed the action |
| action | string | Yes | `parafered`, `advised`, `accorded`, `returned`, `skipped` |
| comment | string | No | Comment or reason |

The dashboard fetches all `parafeeracties` for each displayed voorstel to determine when the current step was activated (the `createdAt` of the last `parafeeractie` before the current step, or the voorstel's `updatedAt` if no prior action exists).

## API Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/objects/voorstel?status=in_parafering` | `NoAdminRequired` | Fetch all active voorstellen for the secretariaat dashboard (OpenRegister built-in) |
| GET | `/api/objects/voorstel?currentStepActor={uid}` | `NoAdminRequired` | Fetch voorstellen where the current user is the active step actor (personal inbox — OpenRegister filter) |
| GET | `/api/parafeer-actie?voorstel={uuid}` | `NoAdminRequired` | Fetch parafeeracties for a voorstel — reused from parafering-actions (for days-waiting calculation and timeline) |
| POST | `/api/parafeer-herinnering` | `NoAdminRequired` + secretariaat group check | Send reminder notification to the current step actor for a voorstel |

**POST `/api/parafeer-herinnering` request body:**

```json
{
  "voorstel": "uuid-of-voorstel"
}
```

**Response on success (201):**

```json
{
  "message": "Herinnering verstuurd",
  "voorstelId": "uuid-of-voorstel",
  "actor": "uid-of-notified-actor"
}
```

Authorization logic (enforced in `ParafeerHerinneringService`):
- Fetch voorstel → check that `status` = `in_parafering`; throw `OCSBadRequestException` with `'Voorstel is not in parafering'` if not
- Derive current step actor from `routeSnapshot[currentStep - 1].actor`
- Send Nextcloud notification to the actor via `NotificatieService`
- Log reminder in the parafering audit trail (stored as a `parafeeractie` with `action` = `reminder` or as a note in the voorstel's `auditTrail`)
- Caller identity derived from `IUserSession` — the secretariaat check is a group membership check

## Seed Data

The following seed objects MUST be included in `procest_register.json` under `components.objects[]`. All slugs are unique for idempotent re-import.

### voorstel — 4 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "voorstel",
    "slug": "voorstel-collegeadvies-omgevingsvergunning-0101"
  },
  "case": "zaak-omgevingsvergunning-0101",
  "type": "Collegeadvies",
  "onderwerp": "Omgevingsvergunning uitbreiding bedrijventerrein De Hoek",
  "steller": "m.bakker",
  "afdeling": "Ruimtelijke Ordening",
  "portefeuillehouder": "wethouder.van.dam",
  "status": "in_parafering",
  "currentStep": 2,
  "routeSnapshot": "[{\"step\":1,\"label\":\"Juridisch advies\",\"type\":\"advies\",\"actor\":\"j.devries\"},{\"step\":2,\"label\":\"Afdelingshoofd parafeert\",\"type\":\"parafering\",\"actor\":\"p.janssen\"},{\"step\":3,\"label\":\"Wethouder accordeert\",\"type\":\"accordering\",\"actor\":\"wethouder.van.dam\"}]"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "voorstel",
    "slug": "voorstel-dt-advies-subsidieregeling-0055"
  },
  "case": "zaak-subsidieregeling-0055",
  "type": "DT-advies",
  "onderwerp": "Subsidieregeling duurzame woningverbetering 2026",
  "steller": "s.devos",
  "afdeling": "Beleid en Subsidies",
  "portefeuillehouder": "wethouder.de.groot",
  "status": "in_parafering",
  "currentStep": 1,
  "routeSnapshot": "[{\"step\":1,\"label\":\"Beleidsadvies\",\"type\":\"advies\",\"actor\":\"k.vermeulen\"},{\"step\":2,\"label\":\"DT-akkoord\",\"type\":\"accordering\",\"actor\":\"h.vanderberg\"}]"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "voorstel",
    "slug": "voorstel-raadsvoorstel-begroting-0200"
  },
  "case": "zaak-begroting-2026-0200",
  "type": "Raadsvoorstel",
  "onderwerp": "Programmabegroting 2027 — vaststelling",
  "steller": "a.vanleeuwen",
  "afdeling": "Financiën",
  "portefeuillehouder": "wethouder.van.dam",
  "status": "in_parafering",
  "currentStep": 3,
  "routeSnapshot": "[{\"step\":1,\"label\":\"Financieel advies\",\"type\":\"advies\",\"actor\":\"r.pieters\"},{\"step\":2,\"label\":\"Concerncontroller\",\"type\":\"parafering\",\"actor\":\"t.hoffmann\"},{\"step\":3,\"label\":\"Gemeentesecretaris\",\"type\":\"parafering\",\"actor\":\"c.dekker\"},{\"step\":4,\"label\":\"Burgemeester accordeert\",\"type\":\"accordering\",\"actor\":\"burgemeester.smit\"}]"
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "voorstel",
    "slug": "voorstel-collegeadvies-parkeerterrein-0142"
  },
  "case": "zaak-parkeerterrein-raadhuis-0142",
  "type": "Collegeadvies",
  "onderwerp": "Uitbreiding parkeerterrein Raadhuis — inrichtingsplan",
  "steller": "p.janssen",
  "afdeling": "Beheer Openbare Ruimte",
  "portefeuillehouder": "wethouder.de.groot",
  "status": "in_parafering",
  "currentStep": 1,
  "routeSnapshot": "[{\"step\":1,\"label\":\"Verkeerskundig advies\",\"type\":\"advies\",\"actor\":\"f.bosman\"},{\"step\":2,\"label\":\"Afdelingshoofd parafeert\",\"type\":\"parafering\",\"actor\":\"h.vanderberg\"},{\"step\":3,\"label\":\"Wethouder accordeert\",\"type\":\"accordering\",\"actor\":\"wethouder.de.groot\"}]"
}
```

## Reuse Analysis

The following existing OpenRegister and platform capabilities are reused — no custom implementations needed for these:

| Capability | Platform Component | Reuse |
|------------|-------------------|-------|
| `voorstel` listing and filtering | `GET /api/objects/voorstel` with query params (OpenRegister built-in) | Dashboard fetches `status=in_parafering`; inbox fetches `currentStepActor={uid}` |
| `parafeeractie` retrieval | `GET /api/parafeer-actie?voorstel={uuid}` (parafering-actions) | Used for days-waiting calculation and timeline display |
| Action dialog for quick inbox actions | `ParafeerActieDialog.vue` (parafering-actions) | Reused directly in `ParafeerInbox.vue` — no duplicate action UI |
| Nextcloud notifications | `NotificatieService` (platform) | Used by `ParafeerHerinneringService` for reminder delivery |
| Audit trail | Automatic per-object audit trail (OpenRegister built-in) | Reminder events are logged as notes on the voorstel object |
| Table layout | `CnTableLayout` component (`@conduction/nextcloud-vue`) | Used in `VoorstellenList.vue` for sortable table rendering |
| Empty state | `CnEmptyState` component (`@conduction/nextcloud-vue`) | Used in both `VoorstellenList.vue` and `ParafeerInbox.vue` |
| Route snapshot parsing | Existing `routeSnapshot` field on `voorstel` (parafeerroute-engine) | Parsed on frontend to derive step name, actor, and total step count |

**No overlap found** with existing services for reminder-specific logic. The custom `ParafeerHerinneringService` is justified because it must: validate that the voorstel is in the correct status, derive the current step actor from `routeSnapshot`, send a targeted notification with voorstel context, and log the reminder event — a multi-step orchestration not provided by the platform CRUD layer.
