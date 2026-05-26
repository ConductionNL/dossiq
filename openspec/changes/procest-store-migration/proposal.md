# Proposal: procest-store-migration

## Why

Procest's Pinia stores have accumulated technical debt around OpenRegister object CRUD. Call sites invoke methods that no longer exist on the canonical `useObjectStore` surface exported by `@conduction/nextcloud-vue`, leading to runtime failures of the form `TypeError: objectStore.X is not a function` — the same class of bug as decidesk #162.

The root cause is drift between procest's internal store layer and the library's published API. Each Pinia sub-store (bezwaar, advice, enforcement, gis, inspection, workflow) has its own CRUD conventions; some call phantom methods on the old API, others use inconsistent parameter shapes (`filters: {}` object vs `_filters[field]=value` query keys). This inconsistency makes it hard to reason about correctness and blocks library upgrades.

## What

Standardize all OpenRegister object CRUD operations in procest to use the canonical `useObjectStore` API surface from `@conduction/nextcloud-vue`:

1. **Identify all Pinia store call sites** that operate on OpenRegister objects across procest sub-stores (bezwaar, advice, enforcement, gis, inspection, workflow, etc.).
2. **Replace phantom method calls** with the canonical API methods:
   - `objectStore.fetchCollection(type, params)` — with filter params using `_filters[field]=value` shape
   - `objectStore.fetchObject(type, id)` — for single-object load
   - `objectStore.saveObject(type, data)` — with `data.id` set for updates (no separate `create`/`update` methods)
   - `objectStore.deleteObject(type, id)` — (no legacy `delete(type, id)` name)
   - `objectStore.uploadFiles(type, objectId, formData)` — for file attachments
   - `objectStore.resolveReferences(object)` — for reference resolution (if used)
3. **Leave procest-specific config stores untouched** — stores wrapping procest REST endpoints (`/apps/procest/api/settings`, `/apps/procest/api/zgw-mappings`) remain as plain `defineStore` (out of scope for the library's `useObjectStore`).
4. **Preserve observable behavior** — business logic, error handling, and component contracts remain unchanged; only the internal store-to-library call path is standardized.

## Capabilities

### New Capabilities

- `procest-canonical-store-api`: All Pinia stores that wrap OR object CRUD use the canonical `useObjectStore` API surface. Method calls are guaranteed to exist on the library version deployed.

### Modified Capabilities

- `procest-object-crud` (existing, implicit) — no observable behavior change. The internal implementation path changes; call sites continue to receive the same return types and error behavior.

## Affected Projects

- [x] Project: `procest` — all implementation work in this repo
- Reference: `@conduction/nextcloud-vue` (library providing `useObjectStore`)
- Reference: ADR-004 (frontend architecture; Pinia store patterns)
- Reference: decidesk #162 (prior phantom-method bug)

## Scope

### In Scope

- All Pinia sub-stores that call `useObjectStore()` internally
- Replacing phantom method calls with canonical API methods
- Updating filter parameter shapes (from `filters: {}` object to `_filters[field]=value`)
- Unit and integration tests verifying canonical API usage
- Migration verification via linting / grep rules

### Out of Scope

- Procest-specific config stores (settings, ZGW mappings) — these are NOT OR objects
- Workflow / business-logic stores that use OR objects internally but remain plain `defineStore` — their internal calls MUST use canonical API, but the stores themselves are not converted to `createObjectStore`
- Modifying the library's `useObjectStore` API or adding new methods
- Refactoring business logic on top of the store layer

## Success Criteria

- All Pinia stores that call `useObjectStore()` use only methods from the canonical API (fetchCollection, fetchObject, saveObject, deleteObject, uploadFiles, resolveReferences).
- No `objectStore.create()`, `objectStore.update()`, `objectStore.delete()` calls remain in procest code (linted via grep).
- All call sites that fetch collections use `_filters[field]=value` query parameter shape, not `filters: {}` objects.
- Unit tests cover canonical API usage for each sub-store's CRUD paths.
- Integration tests verify end-to-end object CRUD in procest workflows (bezwaar, advice, enforcement, etc.).
- All tests pass and CI is green.
