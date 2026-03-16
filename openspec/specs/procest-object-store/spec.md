# procest-object-store Specification

## Purpose
Define the Pinia-based object store that provides the data layer for Procest. The store queries OpenRegister directly from the frontend for all CRUD, search, and pagination operations — following the softwarecatalog thin-client pattern.

## ADDED Requirements

### Requirement: Object store MUST use Pinia with dynamic type registration
The store MUST support registering object types at runtime, each mapped to an OpenRegister register/schema pair.

#### Scenario: Register object type
- GIVEN the app settings have been loaded with register/schema IDs
- WHEN `registerObjectType('case', schemaId, registerId)` is called
- THEN the store MUST record the mapping in `objectTypeRegistry`
- AND subsequent CRUD actions for type `case` MUST use the correct register/schema

#### Scenario: Unregister object type
- GIVEN an object type is registered
- WHEN `unregisterObjectType('case')` is called
- THEN the type MUST be removed from the registry
- AND its cached data MUST be cleared

### Requirement: Object store MUST fetch collections from OpenRegister
The store MUST provide a `fetchCollection` action that queries OpenRegister's list endpoint with pagination and search support.

#### Scenario: Fetch paginated collection
- GIVEN object type `case` is registered with register=5, schema=30
- WHEN `fetchCollection('case', { _limit: 20, _offset: 0 })` is called
- THEN the store MUST fetch `GET /apps/openregister/api/objects/5/30?_limit=20&_offset=0`
- AND the response results MUST be stored in `collections.case`
- AND pagination metadata MUST be stored in `pagination.case`

#### Scenario: Fetch with search
- GIVEN the user searches for "building permit"
- WHEN `fetchCollection('case', { _search: 'building permit' })` is called
- THEN the store MUST include `_search=building+permit` in the query
- AND results MUST reflect the search filter

### Requirement: Object store MUST fetch individual objects
The store MUST provide a `fetchObject` action that retrieves a single object by ID.

#### Scenario: Fetch single object
- GIVEN object type `case` is registered
- WHEN `fetchObject('case', 'uuid-123')` is called
- THEN the store MUST fetch `GET /apps/openregister/api/objects/5/30/uuid-123`
- AND the object MUST be stored in `objects.case['uuid-123']`

### Requirement: Object store MUST support create, update, and delete
The store MUST provide actions for full CRUD operations against OpenRegister.

#### Scenario: Create object
- GIVEN object type `case` is registered
- WHEN `saveObject('case', { title: 'New case', status: 'open' })` is called with no existing ID
- THEN the store MUST POST to `/apps/openregister/api/objects/5/30`
- AND the created object MUST be added to the store

#### Scenario: Update object
- GIVEN a case object exists with ID `uuid-123`
- WHEN `saveObject('case', { id: 'uuid-123', title: 'Updated' })` is called
- THEN the store MUST PUT to `/apps/openregister/api/objects/5/30/uuid-123`
- AND the store MUST update `objects.case['uuid-123']`

#### Scenario: Delete object
- GIVEN a case object exists with ID `uuid-123`
- WHEN `deleteObject('case', 'uuid-123')` is called
- THEN the store MUST DELETE `/apps/openregister/api/objects/5/30/uuid-123`
- AND `objects.case['uuid-123']` MUST be removed from the store

### Requirement: Object store MUST track loading and error states
The store MUST provide reactive loading and error states per object type.

#### Scenario: Loading state during fetch
- GIVEN a collection fetch is in progress for type `case`
- WHEN a component checks `isLoading('case')`
- THEN it MUST return `true`
- AND when the fetch completes, it MUST return `false`

#### Scenario: Error state on failure
- GIVEN an API call fails with a network error
- WHEN the store processes the error
- THEN `errors.case` MUST contain the error message
- AND the loading state MUST be set to `false`

### Requirement: Object store MUST load settings before data operations
The store MUST fetch app settings (register/schema IDs) on initialization before any object type can be registered.

#### Scenario: Settings initialization
- GIVEN the app is loading for the first time
- WHEN the store initializes
- THEN it MUST fetch `/apps/procest/api/settings` to get register/schema configuration
- AND it MUST register all object types using the returned IDs
- AND data fetching MUST NOT proceed until settings are loaded

### Requirement: All API calls MUST include Nextcloud authentication headers
Every fetch request to OpenRegister MUST include the CSRF token and OCS header.

#### Scenario: Authenticated request
- GIVEN a store action makes a fetch call
- WHEN the request is constructed
- THEN it MUST include `requesttoken: OC.requestToken` header
- AND it MUST include `OCS-APIREQUEST: true` header

---

### Current Implementation Status

**Implemented via shared library.** The object store exists but uses `@conduction/nextcloud-vue` rather than a custom implementation.

**Implemented (with file paths):**
- **Object store**: `src/store/modules/object.js` -- uses `createObjectStore('object')` from `@conduction/nextcloud-vue` with plugins: `filesPlugin()` (file uploads/downloads), `auditTrailsPlugin()` (audit trail integration), `relationsPlugin()` (cross-entity reference resolution). The shared library provides: CRUD operations, paginated collection fetching, single object fetching, search, loading state tracking, error state tracking, caching, schema resolution, and authentication headers.
- **Settings store**: `src/store/modules/settings.js` -- fetches `/apps/procest/api/settings` for register/schema configuration. Provides `fetchSettings()` and `saveSettings()` actions. Tracks `loading`, `error`, and `initialized` state. Includes CSRF token and OCS headers on all requests.
- **Settings initialization**: The settings store's `initialized` flag ensures configuration is loaded before data operations proceed.
- **Authentication**: Both stores include `requesttoken: OC.requestToken` and `OCS-APIREQUEST: true` headers.

**Architecture difference from spec:**
- The spec describes a custom store with explicit methods: `registerObjectType()`, `unregisterObjectType()`, `fetchCollection()`, `fetchObject()`, `saveObject()`, `deleteObject()`, `isLoading()`. The actual implementation delegates all of this to the shared library's `createObjectStore()` function, which provides equivalent functionality with a different API surface.
- The shared library manages the object type registry, pagination metadata, and per-type error/loading states internally.
- Object types are registered based on the register/schema configuration from settings, not via explicit `registerObjectType()` calls in application code.

**What the shared library provides (not visible in Procest code but functionally present):**
- Dynamic type registration mapped to register/schema pairs
- Collection fetching with `_limit`, `_offset`, `_search`, `_sort`, `_order`, and `_filters` parameters
- Single object fetching by ID
- Create (POST), Update (PUT), Delete (DELETE) operations
- Per-type loading and error state tracking
- Pagination metadata per type
- Object caching

### Standards & References

- **Nextcloud authentication**: CSRF token and OCS header per Nextcloud API conventions.
- **OpenRegister API**: REST API at `/apps/openregister/api/objects/{register}/{schema}` for all operations.
- **Pinia**: State management with Vue 2 compatibility via `PiniaVuePlugin`.

### Specificity Assessment

- **The spec describes the right functionality but the wrong API.** The actual implementation uses a shared library (`@conduction/nextcloud-vue`) that provides all described capabilities but with a different method signature. The spec should be updated to document the shared library pattern or acknowledge that the implementation delegates to it.
- **Well-specified for custom implementation**, but since the shared library is used across all Conduction apps (Procest, Pipelinq, Softwarecatalog, etc.), documenting the library's API would be more accurate.
- **Open questions:**
  - Should this spec document the shared library's API or Procest-specific wrappers?
  - Does the shared library's caching behavior match the spec's expectations?
  - How are per-type errors exposed -- as `errors.case` or via a getter function?
