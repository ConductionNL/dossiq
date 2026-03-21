# pipelinq-object-store Specification

## Problem
Define the Pinia-based object store that provides the data layer for Pipelinq. The store uses `createObjectStore` from `@conduction/nextcloud-vue` to query OpenRegister directly from the frontend for all CRUD, search, pagination, file management, audit trails, and relation resolution operations.

## Proposed Solution
Implement pipelinq-object-store Specification following the detailed specification. Key requirements include:
- Requirement 1: Object store MUST use createObjectStore from shared library
- Requirement 2: Object store MUST support dynamic object type registration
- Requirement 3: Object store MUST fetch collections from OpenRegister
- Requirement 4: Object store MUST fetch individual objects by ID
- Requirement 5: Object store MUST support create, update, and delete operations

## Scope
This change covers all requirements defined in the pipelinq-object-store specification.

## Success Criteria
#### Scenario 1.1: Store creation with plugins
#### Scenario 1.2: Store singleton pattern
#### Scenario 1.3: Store available after Pinia initialization
#### Scenario 2.1: Register object type from settings
#### Scenario 2.2: Register all Pipelinq object types
