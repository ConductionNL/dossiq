# Design: case-location

## Architecture Overview

Case-location introduces a `location` entity that lives alongside the existing `case` schema in the Procest register. Cases reference 0..N locations through a back-reference on the location object (`location.case = <caseUuid>`), keeping the case schema slim and allowing locations to be added, edited, and removed independently of the case write path. Address validation, reverse geocoding, and BAG resolution are concentrated in a single `LocationService` that delegates outbound HTTP to a `PdokLocatieserverClient`; the client is routed through OpenConnector when the municipality requires a proxied egress.

```
CaseDetail.vue
└── CaseLocationsTab.vue           ← lists locations for the case
    ├── LocationCard.vue           ← per-location row: address, source, accuracy
    └── LocationEditDialog.vue     ← add / edit one location

AdminSettings.vue
└── LocationImportTab.vue          ← CSV upload with dry-run + commit
```

```
LocationController
└── LocationService
    ├── PdokLocatieserverClient    ← /suggest, /lookup, /reverse
    └── OpenRegister object store  ← persist `location` objects
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/LocationService.php` | CRUD, address validation, reverse geocoding, BAG resolution |
| `lib/Service/PdokLocatieserverClient.php` | Thin HTTP wrapper around PDOK suggest, lookup, reverse endpoints; OpenConnector-aware |
| `lib/Controller/LocationController.php` | REST: list per case, create, update, delete, suggest (proxied) |
| `lib/Command/ImportCaseLocations.php` | OCC command for CSV import; powers the admin UI flow |
| `src/views/cases/components/CaseLocationsTab.vue` | Case-detail tab listing all locations |
| `src/views/cases/components/LocationCard.vue` | Per-location row |
| `src/views/cases/components/LocationEditDialog.vue` | Add/edit dialog with PDOK autocomplete and map preview |
| `src/views/settings/tabs/LocationImportTab.vue` | Admin CSV upload with dry-run + commit |
| `src/services/locationApi.js` | Frontend client for the location REST endpoints |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `location` schema (slug `location`); add `case_location_schema` config key resolver in `SettingsService` |
| `lib/Service/SettingsService.php` | Register `case_location_schema` config key alongside the other Procest schema keys |
| `appinfo/routes.php` | Register `/api/locations` routes |
| `lib/Repair/EnsureProcestSchemas.php` | Ensure the new `location` schema exists on app install/update |

## Data Model

### `location` schema

- `case` (string, UUID, required) — linked case
- `nummeraanduidingId` (string, nullable) — BAG nummeraanduiding identifier (16-digit); MUST be present when `source = bag`
- `formattedAddress` (string, nullable) — `straat huisnummer[+toev], postcode woonplaats`
- `latitude` (number, nullable) — WGS84 decimal degrees
- `longitude` (number, nullable) — WGS84 decimal degrees
- `parcelId` (string, nullable) — BRK kadastrale aanduiding (e.g. `AMR00-G-1234`)
- `accuracyRadius` (number, nullable) — radius in metres around lat/lng (used when source is gps or free)
- `source` (enum: `bag` | `pdok-reverse` | `gps` | `free` | `import`, required) — provenance
- `label` (string, nullable) — short human label shown in the case header (e.g. "Inspectielocatie 1")
- `createdAt`, `updatedAt` — managed by OpenRegister

A location MUST carry **either** a `nummeraanduidingId` **or** valid `latitude`/`longitude`. Saves that have neither MUST be rejected.

## API Surface

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/locations?case={uuid}` | List locations for a case |
| POST | `/api/locations` | Create a location (server validates against PDOK) |
| PUT | `/api/locations/{id}` | Update a location |
| DELETE | `/api/locations/{id}` | Remove a location |
| GET | `/api/locations/suggest?q=...` | Proxy to PDOK Locatieserver suggest |
| GET | `/api/locations/reverse?lat=...&lng=...` | Proxy to PDOK Locatieserver reverse |
| POST | `/api/locations/import/preview` | CSV dry-run, returns parse + validation report |
| POST | `/api/locations/import/commit` | Commit a previewed import |

## Validation Rules

1. `source = bag` → `nummeraanduidingId` MUST be present and MUST resolve at PDOK Locatieserver `/lookup`.
2. `source = pdok-reverse` → `latitude`/`longitude` MUST be present; the service MUST attempt reverse geocoding and populate `formattedAddress` + `nummeraanduidingId` when a BAG match is found within 25 metres.
3. `source = gps` → `latitude`/`longitude` MUST be present; `accuracyRadius` MUST be present.
4. `source = free` → at least one of `formattedAddress`, `latitude`/`longitude` MUST be present; no BAG resolution is required.
5. `source = import` → reserved for the CSV importer; downgraded to `bag`, `pdok-reverse`, or `free` per row after validation.

## Performance & External-Service Posture

PDOK Locatieserver is public, free, and rate-limited per source IP. The `PdokLocatieserverClient` MUST cache `/lookup` and `/reverse` responses for 24 hours in APCu keyed on the input. When OpenConnector is configured for outbound traffic, the client MUST delegate to it rather than calling PDOK directly, so that the egress audit trail and rate-limit accounting remain centralised.

## Export & Import

CSV columns for both import and export: `caseIdentifier`, `nummeraanduidingId`, `formattedAddress`, `latitude`, `longitude`, `parcelId`, `accuracyRadius`, `source`, `label`. The importer MUST run in two phases — `preview` returns parse errors and per-row validation outcomes without writing; `commit` is invoked with the preview token and writes only the rows that previewed cleanly.

## Migration & Compatibility

The existing `case.geometry` field stays in place during this change. A follow-up data-migration change SHALL backfill `location` rows from existing `case.geometry` GeoJSON. New cases SHOULD use `location` rows from the start; the case detail UI MUST surface `location` rows in preference to `case.geometry` when both exist.
