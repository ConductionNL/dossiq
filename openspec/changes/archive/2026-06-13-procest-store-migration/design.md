# Design: procest-store-migration

## Context

### Current State

Procest's Pinia stores use OR objects through a Pinia `useObjectStore()` composition. The challenge: over time, procest code has accumulated calls to methods that no longer exist on the canonical API exported by `@conduction/nextcloud-vue`.

Example phantom calls:
- `objectStore.create(type, data)` — should be `objectStore.saveObject(type, data)` with `data.id` unset
- `objectStore.update(type, id, data)` — should be `objectStore.saveObject(type, {...data, id})`
- `objectStore.delete(type, id)` — should be `objectStore.deleteObject(type, id)`
- `objectStore.fetchCollection(type, {filters: {...}})` — should use `_filters[field]=value` params

These phantom calls fail at runtime only when the code path is exercised (e.g., a user submits a form that triggers an update, or deletes a record). This makes bugs hard to catch in development and creates production incidents.

### Canonical API (from @conduction/nextcloud-vue)

The library exports `useObjectStore()` with these methods:

| Method | Signature | Use Case |
|--------|-----------|----------|
| `fetchCollection` | `(type: string, params?: object) => Promise<object[]>` | Load a list of OR objects; filters use `_filters[field]=value` shape |
| `fetchObject` | `(type: string, id: string) => Promise<object>` | Load a single OR object by UUID |
| `saveObject` | `(type: string, data: object) => Promise<object>` | Create (if `data.id` is unset) or update (if `data.id` is set) an OR object |
| `deleteObject` | `(type: string, id: string) => Promise<void>` | Delete an OR object |
| `uploadFiles` | `(type: string, objectId: string, formData: FormData) => Promise<object>` | Upload file(s) attached to an OR object |
| `resolveReferences` | `(object: object) => Promise<object>` | Resolve reference fields to nested objects (optional; may not be used in all stores) |

### Procest Sub-Stores

Procest has several Pinia stores that work with OR objects:

1. **bezwaar.js** — bezwaar (complaint/objection) case management; handles `objection`, `advisoryReport`, `appealDecision` CRUD
2. **advice.js** — advice request workflow; handles `adviesAanvraag` CRUD
3. **enforcement.js** — enforcement action tracking; handles `handhavingsactie` CRUD
4. **gis.js** — GIS layer and map data; handles `mapLayer` CRUD
5. **inspection.js** — inspection workflow; handles `inspectieChecklist` and `inspectieRapport` CRUD
6. **workflow.js** — workflow step and transition routing; reads `workflowStep`, `workflowTemplate` (read-only in most paths)
7. **case.js** — case management core; handles `case`, `decision`, `status`, `role` CRUD

Additionally:

- **settingsStore.js**, **mappingStore.js** — wrap procest-specific config endpoints; NOT OR objects; stay as plain `defineStore`
- **Custom sub-stores** in domain-logic modules (e.g., invoice generation, deadline calculation) — MAY remain as plain `defineStore` as long as their internal OR CRUD calls use canonical API

## File-by-File Migration Plan

### src/store/*.js — Update OR object CRUD Calls

For each store that calls `useObjectStore()`:

**Step 1: Identify all CRUD call sites**
- Search for `create(`, `update(`, `delete(`, `fetch` calls on `objectStore`
- Categorize as phantom (needs fixing) vs canonical (already correct)

**Step 2: Replace phantom methods**

**Before:**
```javascript
// src/store/bezwaar.js
async createObjection(caseId, grounds, channel) {
  const data = {case: caseId, grounds, receivedChannel: channel, receivedDate: new Date()};
  return objectStore.create('objection', data);  // ← phantom method
}
```

**After:**
```javascript
async createObjection(caseId, grounds, channel) {
  const data = {case: caseId, grounds, receivedChannel: channel, receivedDate: new Date()};
  return objectStore.saveObject('objection', data);  // ← canonical API
}
```

**Before (update):**
```javascript
async updateObjection(id, grounds) {
  return objectStore.update('objection', id, {grounds});  // ← phantom method
}
```

**After:**
```javascript
async updateObjection(id, grounds) {
  return objectStore.saveObject('objection', {id, grounds});  // ← canonical API with id in data
}
```

**Before (delete):**
```javascript
async removeObjection(id) {
  return objectStore.delete('objection', id);  // ← phantom method name
}
```

**After:**
```javascript
async removeObjection(id) {
  return objectStore.deleteObject('objection', id);  // ← canonical API name
}
```

**Step 3: Fix filter parameter shapes**

**Before:**
```javascript
async loadObjections(caseId) {
  return objectStore.fetchCollection('objection', {filters: {case: caseId}});  // ← wrong shape
}
```

**After:**
```javascript
async loadObjections(caseId) {
  return objectStore.fetchCollection('objection', {_filters: {case: caseId}});  // ← canonical shape
}
```

Or, if the library prefers query-string notation (check current implementation):
```javascript
async loadObjections(caseId) {
  return objectStore.fetchCollection('objection', {'_filters[case]': caseId});  // ← query-key shape
}
```

### lib/Settings/procest_register.json — No Changes

The data model schema is not affected by this migration. OR objects remain the same; only procest's internal call path changes.

### Tests

**Unit Tests (src/store/__tests__/bezwaar.spec.js, etc.)**
- Mock `objectStore` methods using the canonical API signature
- Verify that store actions call the right method with the right parameters
- Verify that `saveObject` is called with `id` in the data payload for updates

**Integration Tests (tests/integration/store-crud.spec.js)**
- Spin up a test OR instance with procest register
- Create, read, update, delete each entity type via the store
- Verify the API calls reach OR correctly and data persists
- Verify error paths (e.g., 404 on delete of non-existent object)

**Linting & Grep Rules (CI)**
- Grep rule: no `objectStore.create(`, `objectStore.update(`, `objectStore.delete(` in procest src/
- Linting rule (ESLint custom rule or simple lint script): warn if `filters: {...}` is used in `fetchCollection` calls

## Backwards Compatibility

- **Observable behavior unchanged** — component and consumer code continues to work unchanged; store action signatures and return types remain the same.
- **No data migration** — OR object schemas do not change; existing data in the register continues to load unchanged.
- **Library upgrade path** — once procest uses only canonical API, upgrading `@conduction/nextcloud-vue` to a newer major version is safe as long as the canonical methods are preserved.

## Procest-Specific Config Stores (Out of Scope)

**settingsStore.js** and **mappingStore.js** wrap procest-specific REST endpoints:
- `/apps/procest/api/settings` — procest configuration (not OR objects)
- `/apps/procest/api/zgw-mappings` — ZGW service configuration (not OR objects)

These stores remain plain `defineStore` from Pinia. They do NOT call `useObjectStore()` and are therefore out of scope for this migration.

## Seed Data

**No new OR objects or schemas** — this is a refactoring of procest's internal implementation. No new entities are defined in `procest_register.json`.

If the migration requires test fixtures or example data for integration tests, they are created as transient test data (not seed data in the register definition).

## ADRs & Related Decisions

- **ADR-004** (frontend architecture) — Pinia store patterns; this migration clarifies the correct pattern for OR object stores.
- **ADR-001** (data layer) — OR as the canonical store; procest's stores are composable layers on top, not replacements for OR's ObjectService.
- **decidesk #162** (prior phantom-method bug) — this migration prevents a recurrence.
