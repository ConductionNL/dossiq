---
status: implemented
---
# pipelinq-object-store Specification

## Purpose
Define the Pinia-based object store that provides the data layer for Pipelinq. The store uses `createObjectStore` from `@conduction/nextcloud-vue` to query OpenRegister directly from the frontend for all CRUD, search, pagination, file management, audit trails, and relation resolution operations.

## Context
Pipelinq follows the same thin-client architecture as all Conduction Nextcloud apps: no backend CRUD controllers, all data operations go directly from the Vue frontend to OpenRegister's REST API. The object store is powered by the shared `@conduction/nextcloud-vue` library, which provides `createObjectStore()` -- a factory function that returns a Pinia store with full CRUD capabilities, pagination, caching, loading/error state management, and plugin support. Pipelinq extends the base store with three plugins: `filesPlugin` (file attachments), `auditTrailsPlugin` (audit trail integration), and `relationsPlugin` (cross-entity reference resolution). Object types are registered dynamically at runtime based on register/schema IDs fetched from the app's settings endpoint.

## Requirements

### Requirement 1: Object store MUST use createObjectStore from shared library
The store MUST use `createObjectStore('object')` from `@conduction/nextcloud-vue` to create a Pinia store with standardized CRUD operations and plugins.

#### Scenario 1.1: Store creation with plugins
- GIVEN the file `src/store/modules/object.js`
- WHEN the module is imported
- THEN it MUST export `useObjectStore` created by `createObjectStore('object', { plugins: [...] })`
- AND the plugins array MUST include `filesPlugin()`, `auditTrailsPlugin()`, and `relationsPlugin()`
- AND the Pinia store ID MUST be `'object'` to maintain compatibility with shared library components

#### Scenario 1.2: Store singleton pattern
- GIVEN multiple Vue components calling `useObjectStore()`
- WHEN each component accesses the store
- THEN they MUST all receive the same Pinia store instance
- AND state changes in one component MUST be reactive in all others

#### Scenario 1.3: Store available after Pinia initialization
- GIVEN `main.js` registers `PiniaVuePlugin` and creates the Vue instance with `pinia`
- WHEN components call `useObjectStore()` in `setup()` or `computed`
- THEN the store MUST be accessible without errors
- AND it MUST NOT be called before the Vue instance is created (causes "Pinia not installed" error)

### Requirement 2: Object store MUST support dynamic object type registration
The store MUST support registering object types at runtime, mapping each type name to an OpenRegister register/schema pair.

#### Scenario 2.1: Register object type from settings
- GIVEN the settings store has fetched config with `register: "5"` and `client_schema: "40"`
- WHEN `objectStore.registerObjectType('client', '40', '5')` is called during `initializeStores()`
- THEN the store's internal `objectTypeRegistry` MUST map `'client'` to `{ schema: '40', register: '5' }`
- AND subsequent CRUD calls for type `'client'` MUST use register 5 and schema 40

#### Scenario 2.2: Register all Pipelinq object types
- GIVEN the settings config returns IDs for all configured schemas
- WHEN `initializeStores()` calls `registerObjectType` for each
- THEN the following types MUST be registered (if their schema IDs are present): `client`, `request`, `contact`, `lead`, `prospect`, `pipeline`, `pipelineStage`, `product`, `leadSource`, `requestChannel`
- AND types with empty or missing schema IDs MUST be skipped without error

#### Scenario 2.3: Guard against unregistered type operations
- GIVEN object type `invoice` has NOT been registered
- WHEN a component calls `objectStore.fetchCollection('invoice', {})`
- THEN the store MUST log a warning to the console
- AND it MUST return an empty array (or null) without throwing an exception
- AND `loading.invoice` MUST remain `false`

#### Scenario 2.4: Type registry is reactive
- GIVEN object types are registered during `initializeStores()`
- WHEN a component accesses `objectStore.objectTypeRegistry`
- THEN the registry MUST be a reactive Pinia state property
- AND components watching the registry MUST update when types are added

#### Scenario 2.5: Re-registration overwrites previous mapping
- GIVEN type `client` was registered with schema 40, register 5
- WHEN `registerObjectType('client', '41', '6')` is called again (e.g., after settings change)
- THEN the registry MUST update to `{ schema: '41', register: '6' }`
- AND the store MUST clear any cached data for the old schema

### Requirement 3: Object store MUST fetch collections from OpenRegister
The store MUST provide a `fetchCollection` action that queries OpenRegister's list endpoint with pagination, filtering, sorting, and search support.

#### Scenario 3.1: Fetch paginated collection
- GIVEN object type `client` is registered with register=6, schema=40
- WHEN `fetchCollection('client', { _limit: 20, _offset: 0 })` is called
- THEN the store MUST make a GET request to `/apps/openregister/api/objects/6/40?_limit=20&_offset=0`
- AND the response results MUST be stored in the store's collections state for type `client`
- AND pagination metadata (total count, current page, limit) MUST be stored for type `client`

#### Scenario 3.2: Fetch with search query
- GIVEN the user searches for "Gemeente Amsterdam"
- WHEN `fetchCollection('client', { _search: 'Gemeente Amsterdam' })` is called
- THEN the query string MUST include `_search=Gemeente+Amsterdam`
- AND results MUST reflect the search filter applied server-side by OpenRegister

#### Scenario 3.3: Fetch with field filters
- GIVEN the user filters requests by status "open"
- WHEN `fetchCollection('request', { '_filters[status]': 'open' })` is called
- THEN the query string MUST include `_filters%5Bstatus%5D=open`
- AND only requests with status "open" MUST be returned

#### Scenario 3.4: Fetch with sorting
- GIVEN the user sorts clients by name ascending
- WHEN `fetchCollection('client', { _order: JSON.stringify({ name: 'asc' }) })` is called
- THEN the query MUST include the `_order` parameter
- AND results MUST be returned in alphabetical order by name

#### Scenario 3.5: Empty collection response
- GIVEN a search that matches no results
- WHEN `fetchCollection('client', { _search: 'nonexistent12345' })` is called
- THEN the store MUST set `collections.client` to an empty array
- AND `pagination.client.total` MUST be 0
- AND `loading.client` MUST be set to `false`

### Requirement 4: Object store MUST fetch individual objects by ID
The store MUST provide a `fetchObject` action that retrieves a single object by its UUID.

#### Scenario 4.1: Fetch single object
- GIVEN object type `client` is registered with register=6, schema=40
- WHEN `fetchObject('client', 'uuid-456')` is called
- THEN the store MUST make a GET request to `/apps/openregister/api/objects/6/40/uuid-456`
- AND the object MUST be stored in the store's objects state keyed by `'uuid-456'`

#### Scenario 4.2: Return cached object if available
- GIVEN `fetchObject('client', 'uuid-456')` was called previously and the object is cached
- WHEN `fetchObject('client', 'uuid-456')` is called again without force flag
- THEN the store MAY return the cached object without making a network request
- AND components MUST receive the cached data immediately

#### Scenario 4.3: Force refresh bypasses cache
- GIVEN a cached client object with ID `uuid-456`
- WHEN `fetchObject('client', 'uuid-456', { force: true })` is called
- THEN the store MUST make a new GET request to OpenRegister
- AND the cache MUST be updated with the fresh response

#### Scenario 4.4: Fetch non-existent object
- GIVEN no object exists with ID `uuid-999`
- WHEN `fetchObject('client', 'uuid-999')` is called
- THEN the store MUST handle the 404 response gracefully
- AND `errors.client` MUST contain an error message
- AND the store MUST NOT store null/undefined in the objects state

#### Scenario 4.5: getObject getter for synchronous access
- GIVEN a client object with ID `uuid-456` is in the store
- WHEN a component accesses `objectStore.getObject('client', 'uuid-456')`
- THEN it MUST return the cached object synchronously (no API call)
- AND if the object is not cached, it MUST return `null` or `undefined`

### Requirement 5: Object store MUST support create, update, and delete operations
The store MUST provide actions for full CRUD operations against OpenRegister.

#### Scenario 5.1: Create new object
- GIVEN object type `request` is registered with register=6, schema=42
- WHEN `saveObject('request', { title: 'New request', client: 'uuid-456' })` is called with no `id` field
- THEN the store MUST POST to `/apps/openregister/api/objects/6/42`
- AND the response (with server-assigned ID) MUST be added to the store's objects state
- AND the collections cache for type `request` MUST be invalidated

#### Scenario 5.2: Update existing object
- GIVEN a client object exists with ID `uuid-456`
- WHEN `saveObject('client', { id: 'uuid-456', name: 'Updated Name', email: 'new@example.nl' })` is called
- THEN the store MUST PUT to `/apps/openregister/api/objects/6/40/uuid-456`
- AND the store MUST update `objects.client['uuid-456']` with the response data

#### Scenario 5.3: Delete object
- GIVEN a request object exists with ID `uuid-789`
- WHEN `deleteObject('request', 'uuid-789')` is called
- THEN the store MUST DELETE `/apps/openregister/api/objects/6/42/uuid-789`
- AND the object MUST be removed from `objects.request`
- AND the collections cache for type `request` MUST be invalidated

#### Scenario 5.4: Optimistic update on save
- GIVEN a client object is being updated
- WHEN `saveObject()` is called
- THEN the store MAY apply the update optimistically (update local state before API response)
- AND if the API call fails, the store MUST revert to the previous state
- AND the error MUST be recorded in `errors.client`

#### Scenario 5.5: Validation error on create
- GIVEN the OpenRegister schema requires field `name` on clients
- WHEN `saveObject('client', { email: 'test@example.nl' })` is called without `name`
- THEN OpenRegister MUST return a 422 validation error
- AND the store MUST capture the validation error details in `errors.client`
- AND `loading.client` MUST be set to `false`

### Requirement 6: Object store MUST track loading and error states per type
The store MUST provide reactive loading and error states for each registered object type.

#### Scenario 6.1: Loading state during collection fetch
- GIVEN a collection fetch is in progress for type `client`
- WHEN a component checks `objectStore.loading.client` (or equivalent getter)
- THEN it MUST return `true`
- AND when the fetch completes (success or error), it MUST return `false`

#### Scenario 6.2: Loading state during single object fetch
- GIVEN a single object fetch is in progress for type `request`
- WHEN a component checks the loading state
- THEN it MUST return `true` for the specific operation
- AND components MUST be able to show `NcLoadingIcon` based on this state

#### Scenario 6.3: Error state on network failure
- GIVEN the OpenRegister API is unreachable
- WHEN a fetch call fails with a network error
- THEN the error MUST be stored in the store's error state for the relevant type
- AND `console.error` MUST log the error details
- AND the loading state MUST be set to `false`

#### Scenario 6.4: Error state cleared on successful retry
- GIVEN a previous fetch for type `client` failed with an error
- WHEN a subsequent fetch for the same type succeeds
- THEN the error state for `client` MUST be cleared (set to null/empty)

#### Scenario 6.5: Concurrent loading states for different types
- GIVEN fetches are in progress for both `client` and `request` simultaneously
- WHEN a component checks loading states
- THEN `loading.client` and `loading.request` MUST independently reflect their respective states
- AND completion of one MUST NOT affect the other

### Requirement 7: Settings store MUST load configuration before data operations
The settings store MUST fetch app settings on initialization, providing register/schema IDs needed for object type registration.

#### Scenario 7.1: Settings fetch on app load
- GIVEN the app is loading for the first time
- WHEN `initializeStores()` is called in `main.js`
- THEN the settings store MUST fetch `GET /apps/pipelinq/api/settings` with CSRF token and OCS header
- AND the response MUST populate `config`, `openRegisters`, and `isAdmin` in the settings store

#### Scenario 7.2: Object types registered from settings config
- GIVEN the settings fetch returns `{ config: { register: '6', client_schema: '40', request_schema: '42', contact_schema: '43' }, openRegisters: true }`
- WHEN `initializeStores()` processes the config
- THEN it MUST call `objectStore.registerObjectType('client', '40', '6')`
- AND it MUST call `objectStore.registerObjectType('request', '42', '6')`
- AND it MUST call `objectStore.registerObjectType('contact', '43', '6')`
- AND types with empty string values MUST be skipped

#### Scenario 7.3: Settings fetch failure
- GIVEN the settings endpoint returns a 500 error
- WHEN the settings store processes the failure
- THEN `settingsStore.error` MUST contain the error message
- AND `settingsStore.initialized` MUST remain `false`
- AND the App.vue MUST display a loading state (since `storesReady` depends on initialization)

#### Scenario 7.4: Settings save action
- GIVEN an admin user changes the register configuration
- WHEN `settingsStore.saveSettings({ register: '7', client_schema: '50' })` is called
- THEN it MUST POST to `/apps/pipelinq/api/settings` with the JSON body
- AND on success, `settingsStore.config` MUST be updated with the response

#### Scenario 7.5: Settings provide isAdmin and hasOpenRegisters
- GIVEN the settings fetch returns `{ openRegisters: true, isAdmin: true }`
- WHEN components check `settingsStore.hasOpenRegisters` and `settingsStore.getIsAdmin`
- THEN the getters MUST return the correct boolean values
- AND App.vue MUST use `hasOpenRegisters` to decide whether to render the main content or the missing-dependency screen

### Requirement 8: All API calls MUST include Nextcloud authentication headers
Every HTTP request to OpenRegister or the app's own API MUST include CSRF token and OCS authentication headers.

#### Scenario 8.1: CSRF token on every request
- GIVEN a store action makes a fetch call to any Nextcloud API
- WHEN the request headers are constructed
- THEN it MUST include `requesttoken: OC.requestToken`
- AND `OC.requestToken` MUST be read from the global `OC` object at request time (not cached at module load)

#### Scenario 8.2: OCS header on every request
- GIVEN a store action makes a fetch call
- WHEN the request headers are constructed
- THEN it MUST include `OCS-APIREQUEST: true`
- AND it MUST include `Content-Type: application/json` for POST/PUT requests

#### Scenario 8.3: Authentication failure handling
- GIVEN the CSRF token has expired (session timeout)
- WHEN a fetch call returns a 401 or CSRF validation error
- THEN the store MUST handle the error gracefully
- AND it MAY trigger a page reload to refresh the token

### Requirement 9: Files plugin MUST support file operations on objects
The `filesPlugin()` MUST add file upload, download, and listing capabilities to the object store.

#### Scenario 9.1: Upload file to object
- GIVEN a client object with ID `uuid-456`
- WHEN the user uploads a document via the file attachment UI
- THEN the files plugin MUST POST the file to OpenRegister's file endpoint for that object
- AND the file metadata MUST be stored as part of the object's file references

#### Scenario 9.2: List files for object
- GIVEN a request object with 3 attached files
- WHEN the detail view loads and requests file listing
- THEN the files plugin MUST fetch the file list from OpenRegister
- AND each file entry MUST include filename, size, mime type, and download URL

#### Scenario 9.3: Download file from object
- GIVEN a file attached to a client object
- WHEN the user clicks the download button
- THEN the files plugin MUST initiate a download from OpenRegister's file endpoint
- AND the file MUST be served with the correct Content-Disposition header

### Requirement 10: Relations plugin MUST resolve cross-entity references
The `relationsPlugin()` MUST automatically resolve references between related objects (e.g., request -> client).

#### Scenario 10.1: Resolve client reference on request
- GIVEN a request object with field `client: 'uuid-456'`
- WHEN the request detail view loads
- THEN the relations plugin MUST detect the client reference and fetch the full client object
- AND the resolved client data MUST be available alongside the request data

#### Scenario 10.2: Resolve multiple references
- GIVEN a client object with multiple request references
- WHEN the client detail view loads
- THEN the relations plugin MUST resolve all referenced requests
- AND the resolved requests MUST be available as a collection

#### Scenario 10.3: Circular reference protection
- GIVEN object A references object B which references object A
- WHEN the relations plugin resolves references
- THEN it MUST detect the cycle and stop resolution after one level
- AND it MUST NOT enter an infinite loop

---

## Current Implementation Status

**Implemented via shared library.** The Pipelinq object store exists and uses `@conduction/nextcloud-vue` rather than a custom implementation.

**Implemented (with file paths -- in `pipelinq/` submodule):**
- **Object store**: `pipelinq/src/store/modules/object.js` -- uses `createObjectStore('object')` from `@conduction/nextcloud-vue` with `filesPlugin()`, `auditTrailsPlugin()`, and `relationsPlugin()`. The shared library provides all CRUD, pagination, caching, search, loading/error state tracking, and authentication headers.
- **Settings store**: `pipelinq/src/store/modules/settings.js` -- fetches `/apps/pipelinq/api/settings` to get register/schema configuration, with loading and error state tracking. Includes `fetchSettings()` and `saveSettings()` actions with CSRF token and OCS headers.
- **Store initialization**: `pipelinq/src/store/store.js` -- `initializeStores()` fetches settings then registers all object types from the config.
- **Authentication headers**: Both stores include `requesttoken: OC.requestToken` and `OCS-APIREQUEST: true` in all fetch calls.

**Architecture note:** The spec describes both the generic shared library API and Pipelinq-specific type registration. The `createObjectStore()` function provides `registerObjectType()`, `fetchCollection()`, `fetchObject()`, `saveObject()`, `deleteObject()`, and loading/error state tracking internally. Pipelinq-specific code only needs to call `registerObjectType()` with the correct schema/register IDs during initialization.

## Standards & References

- **Nextcloud authentication**: CSRF token via `OC.requestToken` and OCS header per Nextcloud API conventions.
- **OpenRegister API**: REST API at `/apps/openregister/api/objects/{register}/{schema}` for all CRUD operations.
- **Pinia**: State management with Vue 2 compatibility via `PiniaVuePlugin`.
- **@conduction/nextcloud-vue**: Shared library providing `createObjectStore`, used by Procest, Pipelinq, Softwarecatalog, and other Conduction apps.

## Specificity Assessment

This spec is highly detailed with 10 requirements and comprehensive scenarios. It documents both the shared library pattern (how `createObjectStore` works) and the Pipelinq-specific configuration (which types are registered, what settings are needed). The spec is implementable as-is, and the core functionality is already implemented via the shared library.
