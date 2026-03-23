# procest-object-store Specification

## Problem
Define the Pinia-based object store that provides the data layer for Procest. The store uses `createObjectStore` from `@conduction/nextcloud-vue` to query OpenRegister directly from the frontend for all CRUD, search, pagination, file management, audit trails, and relation resolution operations -- following the thin-client pattern where Procest owns no database tables.

## Proposed Solution
Implement procest-object-store Specification following the detailed specification. Key requirements include:
- Requirement 1: Object store MUST use createObjectStore from shared library with plugins
- Requirement 2: Object store MUST register all Procest entity types dynamically
- Requirement 3: Object store MUST fetch collections with pagination and search
- Requirement 4: Object store MUST fetch individual objects by ID
- Requirement 5: Object store MUST support create, update, and delete

## Scope
This change covers all requirements defined in the procest-object-store specification.

## Success Criteria
#### Scenario 1.1: Store creation with three plugins
#### Scenario 1.2: filesPlugin provides attachment operations
#### Scenario 1.3: auditTrailsPlugin provides history tracking
#### Scenario 1.4: relationsPlugin resolves cross-entity references
#### Scenario 1.5: Store singleton across all components
