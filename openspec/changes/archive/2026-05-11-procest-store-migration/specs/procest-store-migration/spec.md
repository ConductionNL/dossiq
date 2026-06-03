# procest-store-migration

## ADDED Requirements

### Requirement: Procest MUST use `@conduction/nextcloud-vue` `useObjectStore` for all OpenRegister object CRUD

All Pinia store call sites in procest that operate on OpenRegister objects MUST invoke methods that are part of the canonical `useObjectStore` surface exported by `@conduction/nextcloud-vue`. Phantom method calls (method names that no longer exist on the lib) are forbidden because they fail at runtime as `TypeError: objectStore.X is not a function` once the affected code path is exercised — the same class of bug as decidesk #162.

#### Scenario: A view fetches a collection of OpenRegister objects

- **WHEN** procest needs to load a list of OR objects of a given registered type
- **THEN** the call site MUST use `objectStore.fetchCollection(type, params)`
- **AND** filter parameters MUST use the `_filters[field]=value` query-key shape (not a `filters: {}` object)

#### Scenario: A view loads a single OpenRegister object by ID

- **WHEN** procest needs to load one OR object by its UUID
- **THEN** the call site MUST use `objectStore.fetchObject(type, id)`

#### Scenario: A sub-store creates or updates an OpenRegister object

- **WHEN** procest creates or updates an OR object
- **THEN** the call site MUST use `objectStore.saveObject(type, data)` (with `data.id` set for updates)
- **AND** MUST NOT use the legacy `create(type, data)` or `update(type, id, data)` shapes

#### Scenario: A sub-store deletes an OpenRegister object

- **WHEN** procest deletes an OR object
- **THEN** the call site MUST use `objectStore.deleteObject(type, id)`
- **AND** MUST NOT use the legacy `delete(type, id)` name

#### Scenario: A sub-store uploads a file attached to an OpenRegister object

- **WHEN** procest uploads a file attached to an OR object
- **THEN** the call site MUST use the `filesPlugin`-supplied `objectStore.uploadFiles(type, objectId, formData)` action
- **AND** the file MUST be wrapped in a `FormData` instance, not passed as a raw `File`

### Requirement: Procest-specific config stores MAY remain as plain Pinia `defineStore`s

The system SHALL satisfy the behaviour described as "Procest-specific config stores MAY remain as plain Pinia `defineStore`s".

Procest carries config endpoints (`/apps/procest/api/settings`, `/apps/procest/api/zgw-mappings`) that are not OpenRegister objects. These stores are out of scope for the lib's `useObjectStore`.

#### Scenario: A store wraps a procest-specific REST endpoint

- **WHEN** the store's responsibility is a procest-specific REST endpoint (settings, ZGW mappings) and not OpenRegister object CRUD
- **THEN** it MAY remain a plain `defineStore` from `pinia`
- **AND** it MUST NOT be required to migrate to `createObjectStore`

### Requirement: Workflow / business-logic Pinia stores MAY remain as plain `defineStore`s

Procest's bezwaar, advice, enforcement, gis, inspection, and workflow stores model app-specific business state (deadline calculation, LHS matrix, escalation rules) on top of the lib's object store. Their internal OR-object CRUD calls MUST follow the canonical API, but the stores themselves MAY remain plain `defineStore`s.

#### Scenario: A sub-store wraps domain logic on top of OR object CRUD

- **WHEN** a sub-store implements domain logic (e.g. AWB deadline rules, LHS matrix lookup, escalation) and uses `useObjectStore` internally for CRUD
- **THEN** the sub-store MAY remain a plain `defineStore`
- **AND** every internal `useObjectStore()` call MUST use the canonical lib API (`fetchCollection`, `fetchObject`, `saveObject`, `deleteObject`, `uploadFiles`, `resolveReferences`)
