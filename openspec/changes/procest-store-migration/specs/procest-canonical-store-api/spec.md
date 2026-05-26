# procest-canonical-store-api Specification

---
status: proposed
---

## Purpose

Procest's Pinia stores that wrap OpenRegister object CRUD MUST use only the canonical `useObjectStore` API surface from `@conduction/nextcloud-vue`. Runtime failures from phantom method calls (e.g., `TypeError: objectStore.X is not a function`) MUST be eliminated by replacing all non-canonical method invocations with their canonical equivalents.

## ADDED Requirements

### Requirement: A View Fetches a Collection of OpenRegister Objects

All call sites that load a list of OR objects of a registered type MUST use `objectStore.fetchCollection(type, params)` with filter parameters in the `_filters[field]=value` query-key shape (not a `filters: {}` object).

#### Scenario: Store action loads objects with filter parameters

- **GIVEN** a Pinia store action that needs to load a list of OR objects of a registered type
- **WHEN** the action invokes `objectStore` to fetch the collection
- **THEN** the action MUST call `objectStore.fetchCollection(type, params)`
- **AND** filter parameters MUST use the `_filters[field]=value` shape (or query-key equivalent per the deployed library version)
- **AND** the action MUST NOT call `objectStore.fetch()`, `objectStore.getCollection()`, or phantom fetch methods

#### Scenario: Multiple filters applied in a single query

- **GIVEN** a store action that needs to filter by multiple fields (e.g., case ID and status)
- **WHEN** the action calls `objectStore.fetchCollection()`
- **THEN** the action MUST pass filters as `{_filters: {case: caseId, status: 'open'}}` or `{'_filters[case]': caseId, '_filters[status]': 'open'}`
- **AND** the action MUST NOT use a `filters: {}` object wrapper

---

### Requirement: A View Loads a Single OpenRegister Object by ID

All call sites that load one OR object by its UUID MUST use `objectStore.fetchObject(type, id)`.

#### Scenario: Store action loads a single object by UUID

- **GIVEN** a Pinia store action that needs to load one OR object by its UUID
- **WHEN** the action invokes `objectStore` to fetch the object
- **THEN** the action MUST call `objectStore.fetchObject(type, id)`
- **AND** the action MUST NOT call `objectStore.get()`, `objectStore.getObject()`, or phantom single-fetch methods

#### Scenario: Loaded object is available for component consumption

- **GIVEN** a component that displays a single OR object loaded via a store action
- **WHEN** the component receives the object from the store
- **THEN** the object MUST have all properties that OR returns (id, uuid, uri, version, createdAt, updatedAt, owner, organization, register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked)
- **AND** the component MUST NOT assume additional properties not returned by the library

---

### Requirement: A Sub-Store Creates or Updates an OpenRegister Object

All call sites that create or update an OR object MUST use `objectStore.saveObject(type, data)` with `data.id` set for updates. Phantom `create(type, data)` and `update(type, id, data)` methods MUST NOT be used.

#### Scenario: Store action creates a new OR object

- **GIVEN** a Pinia store action that creates a new OR object
- **WHEN** the action invokes `objectStore` to save the object
- **THEN** the action MUST call `objectStore.saveObject(type, data)`
- **AND** `data.id` MUST be unset (undefined or omitted)
- **AND** the action MUST NOT call `objectStore.create(type, data)` or phantom create methods

#### Scenario: Store action updates an existing OR object

- **GIVEN** a Pinia store action that updates an existing OR object identified by UUID
- **WHEN** the action invokes `objectStore` to save the object
- **THEN** the action MUST call `objectStore.saveObject(type, {...data, id})`
- **AND** `data.id` MUST be set to the object's UUID
- **AND** the action MUST NOT call `objectStore.update(type, id, data)` or phantom update methods

#### Scenario: saveObject request includes all required fields

- **GIVEN** an OR object with required fields (per the schema)
- **WHEN** a store action calls `objectStore.saveObject()`
- **THEN** the `data` payload MUST include all required fields
- **AND** the library will return an error (400+ HTTP status or Promise rejection) if required fields are missing
- **AND** the store action's error handling MUST catch and re-throw or log this error appropriately

---

### Requirement: A Sub-Store Deletes an OpenRegister Object

All call sites that delete an OR object MUST use `objectStore.deleteObject(type, id)`. Phantom `delete(type, id)` method calls (without the Object suffix) MUST NOT be used.

#### Scenario: Store action deletes an OR object

- **GIVEN** a Pinia store action that deletes an OR object
- **WHEN** the action invokes `objectStore` to delete the object
- **THEN** the action MUST call `objectStore.deleteObject(type, id)`
- **AND** `id` MUST be the object's UUID
- **AND** the action MUST NOT call `objectStore.delete(type, id)` or other phantom delete methods

#### Scenario: Delete resolves to empty data on success

- **GIVEN** a successful delete call
- **WHEN** the call completes
- **THEN** the Promise MUST resolve to void or an empty response
- **AND** subsequent `objectStore.fetchObject(type, id)` calls MUST return an error (404 or OR equivalent)

---

### Requirement: A Sub-Store Uploads a File Attached to an OpenRegister Object

All call sites that upload a file attached to an OR object MUST use `objectStore.uploadFiles(type, objectId, formData)`. The file MUST be wrapped in a `FormData` instance, not passed as a raw `File`.

#### Scenario: Store action uploads a file to an OR object

- **GIVEN** a Pinia store action that attaches a file to an OR object
- **WHEN** the action invokes `objectStore` to upload the file
- **THEN** the action MUST call `objectStore.uploadFiles(type, objectId, formData)`
- **AND** `formData` MUST be a JavaScript `FormData` instance
- **AND** the action MUST NOT pass the `File` object directly or use phantom upload methods

#### Scenario: File is stored in OR's file attachment registry

- **GIVEN** a successful file upload
- **WHEN** the upload completes
- **THEN** the object's `files` array MUST include the new file reference
- **AND** subsequent fetches of the object MUST include the file in the `files` array

---

### Requirement: Procest-Specific Config Stores MAY Remain as Plain Pinia defineStore

Procest carries config endpoints (`/apps/procest/api/settings`, `/apps/procest/api/zgw-mappings`) that are not OpenRegister objects. These stores are out of scope for the library's `useObjectStore`.

#### Scenario: A store wraps a procest-specific REST endpoint

- **GIVEN** a Pinia store wrapping a procest-specific REST endpoint (e.g., settings, ZGW mappings)
- **WHEN** the store's primary responsibility is a procest-specific REST endpoint and NOT OpenRegister object CRUD
- **THEN** the store MAY remain a plain `defineStore` from `pinia`
- **AND** it MUST NOT be required to migrate to `useObjectStore` or `createObjectStore`
- **AND** if the store internally calls `useObjectStore()` for any OR object CRUD, those internal calls MUST use the canonical API

---

### Requirement: Workflow / Business-Logic Pinia Stores MAY Remain as Plain defineStore

Procest's bezwaar, advice, enforcement, gis, inspection, and workflow stores model app-specific business state (deadline calculation, LHS matrix, escalation rules) on top of the library's object store. Their internal OR-object CRUD calls MUST follow the canonical API, but the stores themselves MAY remain plain `defineStore`s.

#### Scenario: A sub-store wraps domain logic on top of OR object CRUD

- **GIVEN** a Pinia sub-store implementing domain logic (e.g., AWB deadline rules, LHS matrix lookup, escalation)
- **WHEN** the store uses `useObjectStore()` internally for CRUD
- **THEN** the sub-store MAY remain a plain `defineStore`
- **AND** every internal `useObjectStore()` call MUST use the canonical lib API (`fetchCollection`, `fetchObject`, `saveObject`, `deleteObject`, `uploadFiles`, `resolveReferences`)
- **AND** the store's exported actions (the contract with components) MAY remain unchanged

#### Scenario: Verify no phantom methods in business-logic stores

- **GIVEN** procest stores like `bezwaar.js`, `advice.js`, `enforcement.js`, `inspection.js`
- **WHEN** these stores are inspected
- **THEN** no call to `objectStore.create()`, `objectStore.update()`, `objectStore.delete()` MUST be found
- **AND** all `fetchCollection` calls MUST use `_filters[field]=value` shape

---

## Test Coverage

### Unit Tests

- **Test: fetchCollection uses canonical API** — Mock `objectStore`, verify that a store action that loads objects calls `objectStore.fetchCollection()` with the correct parameters.
- **Test: fetchObject uses canonical API** — Mock `objectStore`, verify that a store action that loads a single object calls `objectStore.fetchObject()`.
- **Test: saveObject for create** — Mock `objectStore`, verify that creating an object calls `objectStore.saveObject(type, data)` with `data.id` unset.
- **Test: saveObject for update** — Mock `objectStore`, verify that updating an object calls `objectStore.saveObject(type, {...data, id})` with `id` set.
- **Test: deleteObject uses canonical API** — Mock `objectStore`, verify that deleting an object calls `objectStore.deleteObject()` (not `delete()`).
- **Test: uploadFiles wraps in FormData** — Mock `objectStore`, verify that file upload calls `objectStore.uploadFiles()` with a FormData instance.

### Integration Tests

- **Test: Full CRUD cycle for each entity** — Create, read, update, delete each procest OR entity (case, objection, adviesAanvraag, handhavingsactie, etc.) via the store; verify data persists in OR.
- **Test: Filter parameters reach OR correctly** — Load a collection with multiple filters via the store; verify the OR API receives the correct query parameters.
- **Test: File upload end-to-end** — Upload a file to an object via the store; verify it appears in the object's `files` array on subsequent fetches.

### Linting Rules (CI)

- **Grep rule:** No `objectStore.create(`, `objectStore.update(`, `objectStore.delete(` calls in `src/` directory.
- **ESLint rule (or lint script):** Warn if `filters: {...}` is used as a parameter in `fetchCollection` calls (suggest `_filters` instead).
