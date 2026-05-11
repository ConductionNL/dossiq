# Procest store migration — adopt `@conduction/nextcloud-vue` `useObjectStore`

## Why

Project memory rule: **"Store pattern guidance — Do not use custom stores; use Options API with createObjectStore."**

Decidesk just hit issue **#162**: its custom store didn't expose the lib's `fetchObject` / `subscribe` API, so live updates silently broke. Procest carries the same anti-pattern shape — phantom method calls (`getObjects`, `getAll`, `update`, `delete`, `create`, `fetchOne`, `uploadFile`) that the lib does not expose. Today they fail at runtime with `TypeError: objectStore.getObjects is not a function` once the affected code path is exercised.

Procest's `src/store/modules/object.js` already calls `createObjectStore('object', { plugins: [filesPlugin(), auditTrailsPlugin(), relationsPlugin()] })`, so the wrapper is correct. What's broken is **call-site drift**: views and sub-stores still invoke the legacy method names that no longer exist. Migrating those call sites to the canonical lib API closes the same class of bug as decidesk #162 before it fires in production.

## What Changes

- **Replace phantom calls with the lib's canonical API:**
  - `getObjects(type, opts)` → `fetchCollection(type, opts)`
  - `getAll(type)` → `fetchCollection(type, {})`
  - `create(type, data)` → `saveObject(type, data)`
  - `update(type, id, data)` → `saveObject(type, { id, ...data })`
  - `delete(type, id)` → `deleteObject(type, id)`
  - `fetchOne(type, id)` → `fetchObject(type, id)`
  - `uploadFile(caseId, file)` → `uploadFiles('case', caseId, formData)` (filesPlugin)
- **Filter param shape**: lib expects `_filters[field]=value` query keys, not a `filters: {}` object. Migrate accordingly.
- Keep the three procest-specific Pinia stores (`settings`, `zgwMapping`, plus the business-logic sub-stores `bezwaar` / `advice` / `enforcement` / `gis` / `inspection` / `workflow`) — these wrap workflow logic, not OR objects, and are explicitly out of scope per the migration rule ("only migrate the OR-object stores").

## Impact

- **Affected specs**: new `procest-store-migration` capability (documents the call-site invariant for future audits).
- **Affected code**: `src/store/modules/{object,bezwaar,gis,inspection}.js`, `src/views/CaseMapView.vue`, `src/views/voorstellen/VoorstelDetail.vue`, `src/views/dashboard/CaseMapWidget.vue`. Estimated ~15 call sites in 7 files.
- **No API contract changes** — server-side endpoints unchanged.
- **No new dependencies** — `@conduction/nextcloud-vue@^1.0.0-beta.12` is already installed.
