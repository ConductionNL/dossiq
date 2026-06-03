---
status: implemented
---

# procest-object-store Specification

## Purpose

@e2e exclude Pinia store factory plumbing; store API compliance is covered by unit tests, not browser E2E.

Define the Pinia-based object store that provides the data layer for Procest. The store uses `createObjectStore` from `@conduction/nextcloud-vue` to query OpenRegister directly from the frontend for all CRUD, search, pagination, file management, audit trails, and relation resolution operations -- following the thin-client pattern where Procest owns no database tables.

## Context
Procest is a case management app where all entities (cases, tasks, statuses, roles, results, decisions, case types, and their supporting types) are stored in OpenRegister. The frontend communicates directly with OpenRegister's REST API through the object store. The store is created by the shared `@conduction/nextcloud-vue` library's `createObjectStore()` factory, extended with plugins for files, audit trails, and relations. Object types are registered dynamically at runtime based on 13+ schema IDs fetched from `SettingsService`. The initialization sequence in `store/store.js` ensures settings are loaded before any types are registered, and the `useListView` composable from the shared library provides standardized list views with search, sort, pagination, and sidebar integration.

## Requirements

### Requirement 1: Object store MUST use createObjectStore from shared library with plugins
The store MUST use `createObjectStore('object')` from `@conduction/nextcloud-vue` to create a Pinia store with full CRUD capabilities and three plugins.

#### Scenario 1.1: Store creation with three plugins
- GIVEN the file `src/store/modules/object.js`
- WHEN the module is imported
- THEN it MUST export `useObjectStore` created by `createObjectStore('object', { plugins: [filesPlugin(), auditTrailsPlugin(), relationsPlugin()] })`
- AND the store ID MUST be `'object'` for compatibility with `CnIndexPage`, `CnDetailPage`, and `useListView`

#### Scenario 1.2: filesPlugin provides attachment operations
- GIVEN the object store is initialized with `filesPlugin()`
- WHEN a case detail view needs to manage file attachments
- THEN the store MUST expose file upload, download, and listing methods for any registered type
- AND file operations MUST use OpenRegister's file API endpoints

#### Scenario 1.3: auditTrailsPlugin provides history tracking
- GIVEN the object store is initialized with `auditTrailsPlugin()`
- WHEN a component requests the audit trail for a case object
- THEN the store MUST fetch and provide the object's modification history from OpenRegister
- AND each audit entry MUST include timestamp, user, and changed fields

#### Scenario 1.4: relationsPlugin resolves cross-entity references
- GIVEN the object store is initialized with `relationsPlugin()`
- WHEN a case object contains `caseType: 'uuid-ct-1'` and `status: 'uuid-st-1'`
- THEN the relations plugin MUST be capable of resolving these references to full objects
- AND the resolved data MUST be available for display in the detail view

#### Scenario 1.5: Store singleton across all components
- GIVEN multiple Vue components call `useObjectStore()` (CaseList, CaseDetail, TaskList, TaskDetail, Dashboard)
- WHEN each component accesses the store
- THEN they MUST all receive the same Pinia store instance
- AND saving a case in CaseDetail MUST be immediately visible in CaseList's reactive state

### Requirement 2: Object store MUST register all Procest entity types dynamically
The store MUST support registering all Procest object types at runtime from settings configuration.

#### Scenario 2.1: Register core entity types
- GIVEN the settings config returns IDs for all Procest schemas
- WHEN `initializeStores()` in `store/store.js` processes the config
- THEN it MUST register the following types (if their schema IDs are present): `case`, `task`, `status`, `role`, `result`, `decision`
- AND each registration MUST call `objectStore.registerObjectType(typeName, schemaId, registerId)`

#### Scenario 2.2: Register type definition types
- GIVEN the settings config includes type schema IDs
- WHEN `initializeStores()` processes the config
- THEN it MUST also register: `caseType`, `statusType`, `resultType`, `roleType`, `propertyDefinition`, `documentType`, `decisionType`
- AND these types MUST be usable for fetching reference data (e.g., case type names, status type lists)

#### Scenario 2.3: Conditional registration based on config
- GIVEN the settings config has `case_schema: "30"` but `document_type_schema: ""`
- WHEN `initializeStores()` processes the config
- THEN `case` MUST be registered with schema 30
- AND `documentType` MUST NOT be registered (empty schema ID)
- AND no error MUST be thrown for the missing schema

#### Scenario 2.4: initializeStores guards with config and register checks
- GIVEN the settings fetch returns `{ register: '5', case_schema: '30', task_schema: '31' }`
- WHEN `initializeStores()` runs in `store/store.js`
- THEN for each type, it MUST check both `config.register` and `config.{type}_schema` are truthy
- AND if `config.register` is falsy, NO types MUST be registered
- AND the function MUST return `{ settingsStore, objectStore }` for use by callers

#### Scenario 2.5: Settings fetch happens before type registration
- GIVEN the app is loading
- WHEN `initializeStores()` executes
- THEN it MUST first call `settingsStore.fetchSettings()` and await the result
- AND only after `config` is populated MUST it proceed to register object types
- AND `App.vue` MUST set `storesReady = true` only after `initializeStores()` resolves

### Requirement 3: Object store MUST fetch collections with pagination and search
The store MUST provide a `fetchCollection` action supporting all OpenRegister query parameters.

#### Scenario 3.1: Fetch paginated case collection
- GIVEN object type `case` is registered with register=5, schema=30
- WHEN `fetchCollection('case', { _limit: 20, _offset: 0 })` is called
- THEN the store MUST make GET `/apps/openregister/api/objects/5/30?_limit=20&_offset=0`
- AND the response results MUST populate the collections state for type `case`
- AND pagination metadata (total, limit, offset) MUST be stored

#### Scenario 3.2: Fetch cases filtered by case type
- GIVEN a case list view filtered to a specific case type
- WHEN `fetchCollection('case', { '_filters[caseType]': 'uuid-ct-1', _limit: 20 })` is called
- THEN only cases with `caseType === 'uuid-ct-1'` MUST be returned
- AND the filter MUST be sent as a query parameter to OpenRegister

#### Scenario 3.3: Fetch status types ordered by position
- GIVEN the case detail view needs status types in order
- WHEN `fetchCollection('statusType', { '_filters[caseType]': caseTypeId, _order: JSON.stringify({ order: 'asc' }), _limit: 100 })` is called
- THEN status types MUST be returned sorted by their `order` field ascending
- AND the result MUST be usable for rendering the StatusTimeline component

#### Scenario 3.4: Fetch tasks filtered by case
- GIVEN the case detail view shows tasks for a specific case
- WHEN `fetchCollection('task', { '_filters[case]': caseId, _limit: 50 })` is called
- THEN only tasks linked to the specified case MUST be returned
- AND the results MUST be usable by `sortTasks()` for display ordering

#### Scenario 3.5: Search across cases
- GIVEN the CaseList uses `CnIndexPage` with search functionality
- WHEN the user types a search term and `fetchCollection('case', { _search: 'building permit' })` is called
- THEN the `_search` parameter MUST be sent to OpenRegister
- AND results MUST be filtered by OpenRegister's full-text search across all indexed fields

### Requirement 4: Object store MUST fetch individual objects by ID
The store MUST provide a `fetchObject` action for retrieving single objects with caching support.

#### Scenario 4.1: Fetch case by ID
- GIVEN object type `case` is registered
- WHEN `fetchObject('case', 'uuid-123')` is called from CaseDetail's `mounted()` hook
- THEN the store MUST make GET `/apps/openregister/api/objects/5/30/uuid-123`
- AND the object MUST be stored in the objects state keyed by `'uuid-123'`
- AND `caseData` computed property MUST reactively update

#### Scenario 4.2: Fetch case type for case detail
- GIVEN a case object has `caseType: 'uuid-ct-1'`
- WHEN CaseDetail calls `fetchObject('caseType', 'uuid-ct-1')` in `loadCaseTypeData()`
- THEN the case type object MUST be fetched and returned
- AND it MUST include fields like `title`, `processingDeadline`, `extensionAllowed`, `extensionPeriod`, `confidentiality`

#### Scenario 4.3: getObject getter for synchronous access
- GIVEN a case object was previously fetched
- WHEN the `CaseDetail` computed property accesses `objectStore.getObject('case', this.caseId)`
- THEN it MUST return the cached object synchronously
- AND if the object is not cached, it MUST return an empty object `{}` (as used in `CaseDetail.vue`)

#### Scenario 4.4: Concurrent fetches for same object deduplicated
- GIVEN two components both call `fetchObject('case', 'uuid-123')` simultaneously
- WHEN the requests are sent
- THEN the store SHOULD deduplicate and make only one API call
- AND both callers MUST receive the same result

#### Scenario 4.5: 404 response handling
- GIVEN an object ID that does not exist in OpenRegister
- WHEN `fetchObject('case', 'uuid-missing')` is called
- THEN the store MUST handle the 404 gracefully
- AND an error MUST be recorded in the store's error state
- AND the loading state MUST be set to false

### Requirement 5: Object store MUST support create, update, and delete
The store MUST provide `saveObject` and `deleteObject` actions for full CRUD.

#### Scenario 5.1: Create new case
- GIVEN the CaseCreateDialog assembles case data with title, caseType, status, startDate, deadline, identifier, activity array
- WHEN `saveObject('case', caseData)` is called with no `id` field
- THEN the store MUST POST to `/apps/openregister/api/objects/5/30`
- AND the response MUST include the server-assigned `id`
- AND the created object MUST be added to the store

#### Scenario 5.2: Update case fields
- GIVEN CaseDetail saves modified fields (title, description, assignee, priority, activity)
- WHEN `saveObject('case', { ...this.caseData, title: 'Updated', activity: [...] })` is called with existing `id`
- THEN the store MUST PUT to `/apps/openregister/api/objects/5/30/{id}`
- AND the response MUST update the cached object in the store

#### Scenario 5.3: Update case status with history
- GIVEN a status change from "In behandeling" to "Afgehandeld" via `executeStatusChange()`
- WHEN `saveObject('case', updateData)` is called with updated `status`, `statusHistory`, `activity`, and optionally `endDate` and `result`
- THEN the full updated object MUST be sent to OpenRegister
- AND the response MUST reflect all changes including the new status

#### Scenario 5.4: Delete case with confirmation
- GIVEN the user confirms case deletion in CaseDetail
- WHEN `deleteObject('case', caseId)` is called
- THEN the store MUST DELETE `/apps/openregister/api/objects/5/30/{caseId}`
- AND the object MUST be removed from the store
- AND the method MUST return a truthy value on success for the router redirect to CaseList

#### Scenario 5.5: Create result object on case closure
- GIVEN a case is being closed (final status selected) and a result type is chosen
- WHEN `saveObject('result', { name: resultType.name, case: caseId, resultType: resultType.id })` is called
- THEN a new result object MUST be created in OpenRegister under the `result` schema
- AND the result MUST be linked to the case by its `case` field

### Requirement 6: Object store MUST track loading and error states per type
The store MUST provide reactive loading and error states accessible by object type.

#### Scenario 6.1: Loading state for case fetch
- GIVEN `CaseDetail` accesses `objectStore.loading.case`
- WHEN a fetch is in progress
- THEN `loading.case` MUST be `true`
- AND the CnDetailPage MUST show its loading state
- AND when the fetch completes, `loading.case` MUST be `false`

#### Scenario 6.2: Multiple types loading simultaneously
- GIVEN CaseDetail calls `fetchObject('case')`, `fetchCollection('statusType')`, `fetchCollection('resultType')`, `fetchCollection('task')`, and `fetchObject('result')` in parallel via `Promise.all`
- WHEN all fetches are in progress
- THEN `loading.case`, `loading.statusType`, `loading.resultType`, `loading.task`, `loading.result` MUST all independently track their states

#### Scenario 6.3: Error state on API failure
- GIVEN OpenRegister returns a 500 error for a task fetch
- WHEN the store processes the error
- THEN `errors.task` MUST contain the error message
- AND `loading.task` MUST be `false`
- AND other types' loading/error states MUST NOT be affected

#### Scenario 6.4: Error cleared on successful retry
- GIVEN a previous fetch for `case` failed
- WHEN a subsequent fetch for `case` succeeds
- THEN the error state for `case` MUST be cleared

### Requirement 7: Settings store MUST load configuration before data operations
The settings store MUST provide app configuration including register/schema IDs, OpenRegister availability, and admin status.

#### Scenario 7.1: Settings fetch on app load
- GIVEN the app is loading
- WHEN `initializeStores()` calls `settingsStore.fetchSettings()`
- THEN it MUST GET `/apps/procest/api/settings` with headers `requesttoken` and `OCS-APIREQUEST`
- AND on success, `settingsStore.config` MUST contain all config keys from `SettingsService::CONFIG_KEYS`
- AND `settingsStore.openRegisters` MUST reflect whether OpenRegister is available
- AND `settingsStore.isAdmin` MUST reflect the current user's admin status
- AND `settingsStore.initialized` MUST be set to `true`

#### Scenario 7.2: Config key completeness
- GIVEN the settings response
- WHEN the config object is populated
- THEN it MUST include at minimum: `register`, `case_schema`, `task_schema`, `status_schema`, `role_schema`, `result_schema`, `decision_schema`, `case_type_schema`, `status_type_schema`, `result_type_schema`, `role_type_schema`, `property_definition_schema`, `document_type_schema`, `decision_type_schema`, `default_case_type`

#### Scenario 7.3: Settings save
- GIVEN an admin changes register/schema configuration
- WHEN `settingsStore.saveSettings(settingsData)` is called
- THEN it MUST POST to `/apps/procest/api/settings` with the JSON body
- AND on success, `settingsStore.config` MUST be updated with the response

#### Scenario 7.4: Settings fetch failure handling
- GIVEN the settings endpoint returns an error
- WHEN the fetch fails
- THEN `settingsStore.error` MUST contain the error message
- AND `settingsStore.config` MUST remain `null`
- AND `settingsStore.loading` MUST be `false`

### Requirement 8: All API calls MUST include Nextcloud authentication headers
Every HTTP request MUST include CSRF token and OCS headers for Nextcloud authentication.

#### Scenario 8.1: Request headers on settings fetch
- GIVEN the settings store makes a fetch call
- WHEN the request is constructed
- THEN it MUST include `requesttoken: OC.requestToken` and `OCS-APIREQUEST: 'true'`
- AND `Content-Type: 'application/json'` MUST be set

#### Scenario 8.2: Request headers on object CRUD
- GIVEN the object store makes any API call via the shared library
- WHEN the shared library constructs the request
- THEN it MUST include the same authentication headers
- AND POST/PUT requests MUST include the request body as JSON

#### Scenario 8.3: Token refresh awareness
- GIVEN the CSRF token may change after long session idle
- WHEN a request fails with token validation error
- THEN the store SHOULD handle the error gracefully
- AND the user SHOULD be prompted to refresh the page

### Requirement 9: useListView composable MUST provide standardized list behavior
List views (CaseList, TaskList) MUST use the `useListView` composable from the shared library for consistent behavior.

#### Scenario 9.1: CaseList uses useListView
- GIVEN `CaseList.vue` calls `useListView('case', { sidebarState, objectStore, defaultSort: { key: 'deadline', order: 'asc' } })` in its `setup()` function
- WHEN the composable initializes
- THEN it MUST return: `objects` (reactive collection), `pagination`, `loading`, `sortKey`, `sortOrder`, `schema`, `visibleColumns`, `refresh()`, `onSort()`, `onPageChange()`
- AND it MUST automatically fetch the schema and initial collection

#### Scenario 9.2: Sidebar integration via sidebarState
- GIVEN the `sidebarState` is injected from `App.vue`
- WHEN `useListView` activates the sidebar
- THEN `sidebarState.active` MUST be set to `true`
- AND `sidebarState.schema` MUST be populated with the object type's schema
- AND sidebar search/filter events MUST trigger collection refetches

#### Scenario 9.3: Sort column change
- GIVEN the case list is sorted by deadline ascending
- WHEN the user clicks the "Title" column header
- THEN `onSort('title')` MUST update `sortKey` to `'title'`
- AND the collection MUST be refetched with the new sort order

#### Scenario 9.4: Page navigation
- GIVEN the case list shows page 1 of 5
- WHEN the user navigates to page 3
- THEN `onPageChange(3)` MUST update the pagination offset
- AND the collection MUST be refetched with `_offset` adjusted accordingly

### Requirement 10: Object store MUST support CnIndexPage and CnDetailPage patterns
The object store MUST be compatible with the shared library's page components for rendering lists and details.

#### Scenario 10.1: CnIndexPage receives store data
- GIVEN `CaseList.vue` renders `CnIndexPage` with `:objects`, `:pagination`, `:loading`, `:schema`, `:sort-key`, `:sort-order`
- WHEN the data is provided from `useListView`
- THEN CnIndexPage MUST render a data table with columns from the schema
- AND it MUST emit `@sort`, `@page-changed`, `@row-click`, `@add`, and `@refresh` events

#### Scenario 10.2: CnDetailPage receives object data
- GIVEN `CaseDetail.vue` renders `CnDetailPage` with `:title`, `:loading`, `:sidebar`, `:sidebar-props`
- WHEN the page renders
- THEN it MUST display the case title, back navigation, and action buttons
- AND the sidebar MUST show the object's raw JSON (provided by the shared library's detail sidebar)

#### Scenario 10.3: Custom column templates
- GIVEN CaseList uses custom slot templates for `identifier`, `caseType`, `status`, and `deadline` columns
- WHEN the data table renders
- THEN the `#column-identifier` slot MUST render with monospace font
- AND `#column-caseType` MUST resolve the case type name via `getCaseTypeName()`
- AND `#column-status` MUST render `QuickStatusDropdown` for inline status changes
- AND `#column-deadline` MUST render with color-coded deadline text (overdue=red, today=warning, ok=green, final=gray)

#### Scenario 10.4: Row click navigation
- GIVEN the case list displays rows
- WHEN the user clicks a row
- THEN `@row-click` MUST trigger `openCase(row)` which calls `$router.push({ name: 'CaseDetail', params: { id: row.id } })`

---

## Current Implementation Status

**Implemented via shared library.** The object store exists and uses `@conduction/nextcloud-vue`.

**Implemented (with file paths):**
- **Object store**: `src/store/modules/object.js` -- `createObjectStore('object')` with `filesPlugin()`, `auditTrailsPlugin()`, `relationsPlugin()`.
- **Settings store**: `src/store/modules/settings.js` -- fetches/saves settings with CSRF and OCS headers, tracks loading/error/initialized state.
- **Store initialization**: `src/store/store.js` -- `initializeStores()` fetches settings then registers 13 object types (case, task, status, role, result, decision, caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType) conditional on schema ID presence.
- **CaseList integration**: `src/views/cases/CaseList.vue` -- uses `useListView('case')` composable with `CnIndexPage`, custom column templates, sidebar integration.
- **CaseDetail integration**: `src/views/cases/CaseDetail.vue` -- uses `CnDetailPage` and `CnDetailCard`, calls `fetchObject`, `fetchCollection`, `saveObject`, `deleteObject` directly on the object store.
- **TaskList/TaskDetail**: `src/views/tasks/TaskList.vue` and `TaskDetail.vue` follow the same pattern.

**All requirements are implemented via the shared library and Procest-specific initialization code.**

## Standards & References

- **Nextcloud authentication**: CSRF token via `OC.requestToken` and OCS header per Nextcloud API conventions.
- **OpenRegister API**: REST API at `/apps/openregister/api/objects/{register}/{schema}` for all CRUD operations.
- **Pinia**: State management with Vue 2 compatibility via `PiniaVuePlugin`.
- **@conduction/nextcloud-vue**: Shared library providing `createObjectStore`, `useListView`, `CnIndexPage`, `CnDetailPage`, `CnDetailCard`, `CnIndexSidebar`. Used across all Conduction apps.
- **CMMN 1.1**: Case lifecycle concepts inform the type system (case, task, status, result, decision).
- **Schema.org**: Type annotations on OpenRegister schemas (case -> `schema:Project`, task -> `schema:Action`).

## Specificity Assessment

This spec is highly detailed with 10 requirements covering store creation, type registration, collection/object fetching, CRUD, loading/error states, settings initialization, authentication, composable usage, and page component integration. It accurately describes both the shared library pattern and Procest-specific usage including the 13+ registered object types, CaseList/CaseDetail integration patterns, and sidebar state management.
