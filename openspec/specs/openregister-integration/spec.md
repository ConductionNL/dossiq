---
status: done
---

# OpenRegister Integration Specification

## Purpose

@e2e exclude Pure data-layer plumbing spec; store/register/schema setup is covered by PHPUnit repair-step tests.

Procest owns **no database tables**. All data is stored as OpenRegister objects in a dedicated `procest` register containing schemas for all entity types. This spec defines how the register and schemas are configured, how the repair step initializes the data model, how the frontend interacts with the OpenRegister API, the Pinia store patterns, cross-entity reference semantics, error handling, pagination, RBAC, cascade behaviors, and performance considerations.

OpenRegister integration is the foundational layer upon which all other Procest features are built.

**Standards**: OpenAPI 3.0.0 (schema format), OpenRegister API conventions
**Feature tier**: MVP (foundation for all features)

**Competitive context**: Most competitors own their data layer directly -- Dimpact ZAC uses PostgreSQL with 89 Flyway migrations, xxllnc Zaken uses PostgreSQL with CQRS event sourcing via RabbitMQ, ArkCase uses JPA/Hibernate with single-table inheritance, and Flowable uses MyBatis with separate runtime/history tables. Procest's approach of delegating all storage to OpenRegister (a separate Nextcloud app) is architecturally unique: it provides schema validation, audit trails, and RBAC without maintaining database migrations, at the cost of being coupled to OpenRegister's API.

---

## Architecture Overview

```
+--------------------------------------------------+
|  Procest Frontend (Vue 2 + Pinia)                |
|  - Object store via @conduction/nextcloud-vue    |
|  - API service layer with error handling         |
+-------------------+------------------------------+
                    | REST API calls
+-------------------v------------------------------+
|  OpenRegister API                                 |
|  /index.php/apps/openregister/api/objects/        |
|  {register}/{schema}/{id}                         |
|  - CRUD operations                               |
|  - Search, pagination, filtering                 |
|  - Schema validation                             |
|  - RBAC enforcement                              |
+-------------------+------------------------------+
                    |
+-------------------v------------------------------+
|  OpenRegister Storage (PostgreSQL)                |
|  - JSON object storage                           |
|  - Schema validation                             |
|  - Audit trail                                   |
+--------------------------------------------------+
```

---

## Register and Schema Definitions

### Register

| Field | Value |
|-------|-------|
| Name | `procest` |
| Slug | `procest` |
| Description | Case management register for Procest |

### Schema Inventory

The register MUST define schemas organized into two groups:

**Configuration schemas** (managed by admins, define case type behavior):

| # | Schema | Purpose | CMMN/Schema.org | ZGW Equivalent |
|---|--------|---------|-----------------|----------------|
| 1 | `caseType` | Case type definition | CaseDefinition / CasePlanModel | ZaakType |
| 2 | `statusType` | Status lifecycle phase per case type | Milestone | StatusType |
| 3 | `resultType` | Case outcome type with archival rules | Case outcome | ResultaatType |
| 4 | `roleType` | Participant role type per case type | schema:Role | RolType |
| 5 | `propertyDefinition` | Custom field definition per case type | schema:PropertyValueSpecification | Eigenschap |
| 6 | `documentType` | Document type requirement per case type | schema:DigitalDocument | InformatieObjectType |
| 7 | `decisionType` | Decision type definition | schema:ChooseAction definition | BesluitType |

**Instance schemas** (created by users during case operations):

| # | Schema | Purpose | CMMN/Schema.org | ZGW Equivalent |
|---|--------|---------|-----------------|----------------|
| 8 | `case` | Case instance | CasePlanModel / schema:Project | Zaak |
| 9 | `task` | Task within a case | HumanTask / schema:Action | (Taak) |
| 10 | `role` | Role assignment on a case | schema:Role instance | Rol |
| 11 | `result` | Case outcome record | Case result | Resultaat |
| 12 | `decision` | Formal decision on a case | schema:ChooseAction instance | Besluit |

**ZGW support schemas** (additional schemas for full ZGW API compliance):

| # | Schema | Purpose | ZGW Equivalent |
|---|--------|---------|----------------|
| 13 | `catalogus` | Catalog grouping | Catalogus |
| 14 | `status` | Status record on a case | Status |
| 15 | `statusRecord` | Status history entry | Status history |
| 16 | `zaaktypeInformatieobjecttype` | Case type to document type link | ZaakType-InformatieObjectType |
| 17 | `caseProperty` | Property value on a case | ZaakEigenschap |
| 18 | `caseDocument` | Document linked to a case | ZaakInformatieObject |
| 19 | `caseObject` | External object linked to a case | ZaakObject |
| 20 | `customerContact` | Contact moment record | Klantcontact |
| 21 | `decisionDocument` | Document linked to a decision | BesluitInformatieObject |
| 22 | `dispatch` | Notification dispatch record | Verzendobject |
| 23 | `document` | Document metadata | EnkelvoudigInformatieObject |
| 24 | `documentLink` | Document-to-document link | -- |
| 25 | `usageRights` | Usage rights on a document | Gebruiksrechten |
| 26 | `kanaal` | Notification channel | Kanaal |
| 27 | `abonnement` | Notification subscription | Abonnement |

---

## Requirements

### REQ-OREG-001: Configuration File

The system MUST define its register and all schemas in a JSON configuration file that follows the OpenAPI 3.0.0 format, consistent with the pattern used by other Conduction apps.

**Tier**: MVP


#### Scenario: Configuration file exists and is valid

- GIVEN the Procest app source code
- THEN the file `lib/Settings/procest_register.json` MUST exist
- AND it MUST be valid JSON
- AND it MUST conform to OpenAPI 3.0.0 format
- AND it MUST define a register with app `procest`
- AND it MUST define all schemas as listed in the schema inventory

#### Scenario: Schema defines required properties for case

- GIVEN the `case` schema definition in `procest_register.json`
- THEN it MUST define the following required properties:
  - `title` (string, max 255)
  - `caseType` (string, format: uuid, reference to caseType)
  - `status` (string, format: uuid, reference to statusType)
  - `startDate` (string, format: date)
- AND it MUST define optional properties including:
  - `description`, `identifier`, `result`, `endDate`, `plannedEndDate`, `deadline`, `confidentiality`, `assignee`, `priority`, `parentCase`, `relatedCases`, `geometry`

#### Scenario: Schema defines required properties for task

- GIVEN the `task` schema definition in `procest_register.json`
- THEN it MUST define:
  - `title` (string, required)
  - `status` (string, enum: available, active, completed, terminated, disabled, required, default: "available")
  - `case` (string, format: uuid, required)
  - `description` (string, optional), `assignee` (string, optional), `dueDate` (string, format: date-time, optional), `priority` (string, enum, optional), `completedDate` (string, format: date-time, optional)

#### Scenario: All schemas include type annotations

- GIVEN each schema definition
- THEN each MUST include `x-schema-org` and `x-zgw-equivalent` annotations
- AND the annotations MUST reference appropriate standards (e.g., case: `schema:Project` / `Zaak`, task: `schema:Action` / `(Taak)`)

#### Scenario: Schema count matches slug-to-config mapping

- GIVEN the `SettingsService::SLUG_TO_CONFIG_KEY` constant
- THEN every schema slug defined in `procest_register.json` MUST have a corresponding entry in the mapping
- AND every mapping entry MUST correspond to a valid `CONFIG_KEYS` entry for persisting the schema ID

---

### REQ-OREG-002: Auto-Configuration on Install (Repair Step)

The system MUST import the register configuration during app installation and upgrades via the Nextcloud repair step mechanism, as implemented in `lib/Repair/InitializeSettings.php`.

**Tier**: MVP


#### Scenario: First install creates register and all schemas

- GIVEN Procest is being installed for the first time on a Nextcloud instance with OpenRegister
- WHEN the repair step `InitializeSettings::run()` executes
- THEN it MUST call `SettingsService::loadConfiguration(force: true)`
- AND `loadConfiguration` MUST call `ConfigurationService::importFromApp()` with the parsed `procest_register.json` content
- AND the `procest` register MUST be created in OpenRegister
- AND all schemas MUST be created with their property definitions
- AND `autoConfigureAfterImport()` MUST persist all register and schema IDs to `IAppConfig`

#### Scenario: Upgrade adds new schemas without data loss

- GIVEN Procest was previously installed with fewer schemas
- AND existing cases, tasks, and roles exist in the register
- WHEN the repair step runs during upgrade
- THEN new schemas MUST be created
- AND existing schemas MUST be updated if their definitions changed (new properties added)
- AND existing objects in unchanged schemas MUST NOT be modified or deleted

#### Scenario: Repair step is idempotent

- GIVEN the repair step has already run successfully
- WHEN the repair step runs again (e.g., during `occ maintenance:repair`)
- THEN it MUST NOT create duplicate registers or schemas
- AND existing data MUST remain intact

#### Scenario: Repair step handles missing OpenRegister gracefully

- GIVEN Procest is installed but OpenRegister is NOT installed
- WHEN the repair step runs
- THEN `SettingsService::isOpenRegisterAvailable()` MUST return false
- AND the repair step MUST log a warning: "OpenRegister is not installed or enabled. Skipping auto-configuration."
- AND the repair step MUST NOT crash or throw an unhandled exception

#### Scenario: Configuration file validation

- GIVEN the `procest_register.json` file contains invalid JSON
- WHEN `loadConfiguration()` is called
- THEN it MUST return `{ success: false, message: 'Invalid JSON in configuration file' }`
- AND no partial import MUST occur

---

### REQ-OREG-003: Frontend API Interaction Patterns

The frontend MUST interact with OpenRegister's REST API for all CRUD operations. All API calls MUST follow consistent URL patterns and error handling.

**Tier**: MVP


#### Scenario: Base URL pattern

- GIVEN the Procest frontend needs to access OpenRegister
- THEN all API calls MUST use the base URL pattern: `/index.php/apps/openregister/api/objects/procest/{schema}`
- AND for single objects: `/index.php/apps/openregister/api/objects/procest/{schema}/{uuid}`

#### Scenario: Create a new case (POST)

- GIVEN the user fills in the new case form with:
  - title: "Bouwvergunning Prinsengracht 200"
  - caseType: "casetype-uuid-omgevings"
  - startDate: "2026-03-01"
- WHEN the user submits the form
- THEN the frontend MUST call `POST /index.php/apps/openregister/api/objects/procest/case`
- AND the request body MUST contain the case properties as JSON
- AND the response MUST include the created object with its generated UUID

#### Scenario: Update an existing case (PUT)

- GIVEN an existing case with UUID "abc-123-def"
- WHEN the user updates the description
- THEN the frontend MUST call `PUT /index.php/apps/openregister/api/objects/procest/case/abc-123-def`
- AND the request body MUST contain the full updated object
- AND the response MUST include the updated object

#### Scenario: Delete a case (DELETE)

- GIVEN an existing case with UUID "abc-123-def"
- WHEN the user deletes the case
- THEN the frontend MUST call `DELETE /index.php/apps/openregister/api/objects/procest/case/abc-123-def`
- AND the response MUST confirm deletion (HTTP 200 or 204)

#### Scenario: API call with authentication

- GIVEN a logged-in Nextcloud user
- THEN all OpenRegister API calls MUST include the Nextcloud session cookie or authorization header
- AND unauthenticated requests MUST be rejected with HTTP 401

---

### REQ-OREG-004: Pagination and Filtering

The frontend MUST support paginated access to object lists and use OpenRegister query parameters for filtering, searching, and sorting.

**Tier**: MVP


#### Scenario: Paginate case list

- GIVEN 24 cases exist in the register
- WHEN the frontend requests page 2 with limit 10
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/procest/case?_page=2&_limit=10`
- AND the response MUST contain cases 11-20
- AND the pagination metadata MUST show: `total: 24`, `page: 2`, `limit: 10`, `pages: 3`

#### Scenario: Filter tasks by case

- GIVEN 23 tasks across 8 cases
- WHEN the frontend requests tasks for case #2024-042 (UUID: "case-uuid-042")
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/procest/task?case=case-uuid-042`
- AND only tasks linked to that case MUST be returned

#### Scenario: Combined filters with sort

- GIVEN the user applies multiple filters: assignee "jan.devries", status "active", sorted by priority
- THEN the frontend MUST combine all filters: `?assignee=jan.devries&status=active&_sort=priority&_order=desc`
- AND the API MUST apply all filters conjunctively (AND logic)

#### Scenario: Search by text field

- GIVEN cases with various titles
- WHEN the user searches for "bouwvergunning"
- THEN the frontend MUST pass the search term via the appropriate OpenRegister search parameter
- AND results MUST include cases whose title contains "bouwvergunning" (case-insensitive)

---

### REQ-OREG-005: Object Store Pattern

The frontend MUST use the `createObjectStore` pattern from `@conduction/nextcloud-vue` for state management, providing a unified store with CRUD actions, loading states, error handling, and pagination.

**Tier**: MVP


#### Scenario: Object store provides CRUD actions

- GIVEN the `useObjectStore()` from `src/store/modules/object.js`
- THEN it MUST provide actions for listing, getting, creating, updating, and deleting objects across all entity types
- AND the store MUST use the `createObjectStore('object')` factory from the shared library
- AND the store MUST include plugins: `filesPlugin()`, `auditTrailsPlugin()`, `relationsPlugin()`

#### Scenario: Store tracks loading state

- GIVEN any object fetch operation
- WHEN the API call is in progress
- THEN the store MUST expose a loading state
- AND the UI MUST show a loading indicator
- AND the loading state MUST be cleared after the API call completes (success or failure)

#### Scenario: Store tracks error state

- GIVEN an API call fails with HTTP 500
- THEN the store MUST capture the error
- AND the UI MUST display a user-friendly error message
- AND the loading state MUST be cleared

#### Scenario: Store resolves cross-references

- GIVEN the `relationsPlugin()` is active
- WHEN a task object with `case: "case-uuid-042"` is loaded
- THEN the store SHOULD resolve the case reference to provide the case title and identifier
- AND resolved references SHOULD be cached to avoid redundant API calls

#### Scenario: Settings store manages app configuration

- GIVEN the `src/store/modules/settings.js` Pinia store
- THEN it MUST provide `fetchSettings()` and `saveSettings()` actions
- AND it MUST interact with `SettingsController` endpoints (`GET /api/settings`, `POST /api/settings`)
- AND it MUST track loading and error states

---

### REQ-OREG-006: Cross-Entity References

Entities in Procest reference each other via UUID. The frontend MUST resolve these references to display meaningful data (titles, names) rather than raw UUIDs.

**Tier**: MVP


#### Scenario: Task references a case

- GIVEN a task object with `case: "case-uuid-042"`
- WHEN the task is displayed in a list or card
- THEN the frontend MUST resolve "case-uuid-042" to display the case identifier and title (e.g., "Case #2024-042 Bouwvergunning Keizersgracht")
- AND the resolved case reference MUST be clickable, navigating to the case detail

#### Scenario: Role references both case and role type

- GIVEN a role object with:
  - `case: "case-uuid-042"`
  - `roleType: "roletype-uuid-handler"`
  - `participant: "jan.devries"`
- WHEN the role is displayed on the case detail page
- THEN the frontend MUST resolve:
  - The role type to its name (e.g., "Behandelaar")
  - The participant to the Nextcloud user display name (e.g., "Jan de Vries") via `/ocs/v2.php/cloud/users/{uid}`

#### Scenario: Dangling reference (referenced object deleted)

- GIVEN a task with `case: "case-uuid-deleted"` where the referenced case has been deleted
- WHEN the task is displayed
- THEN the frontend MUST handle the missing reference gracefully
- AND it SHOULD display a "Case not found" or "[Deleted]" placeholder
- AND the task MUST still be viewable and manageable

#### Scenario: Case type hierarchy resolution

- GIVEN a case detail view that needs to display:
  - The case type name, current status name, handler name, and task list
- WHEN the case detail page loads
- THEN the frontend MUST fetch and resolve all related entities
- AND cross-references MUST be resolved in parallel where possible

---

### REQ-OREG-007: Schema Validation Rules

OpenRegister MUST validate objects against their schema definitions before storage. Procest schemas MUST define appropriate validation constraints.

**Tier**: MVP


#### Scenario: Required field validation

- GIVEN the `task` schema requires `title` and `case`
- WHEN the frontend submits a task without a title
- THEN the OpenRegister API MUST return HTTP 400/422 with a validation error
- AND the error response MUST identify the missing field (`title`)
- AND the frontend MUST display the validation error to the user

#### Scenario: Enum validation for task status

- GIVEN the `task` schema defines `status` as enum: `available`, `active`, `completed`, `terminated`, `disabled`
- WHEN the frontend submits a task with `status: "pending"`
- THEN the OpenRegister API MUST reject the request
- AND the error MUST indicate that "pending" is not a valid value for `status`

#### Scenario: Date format validation

- GIVEN the `case` schema defines `startDate` as format: date
- WHEN the frontend submits a case with `startDate: "not-a-date"`
- THEN the API MUST reject with a format validation error

#### Scenario: String length validation

- GIVEN the `case` schema defines `title` with maxLength: 255
- WHEN the frontend submits a case with a title of 300 characters
- THEN the API MUST reject with a length validation error

---

### REQ-OREG-008: Error Handling

The frontend MUST handle all categories of API errors gracefully and present user-friendly messages.

**Tier**: MVP


#### Scenario: Network error (offline/timeout)

- GIVEN the user is creating a case
- WHEN the API call fails due to a network timeout
- THEN the frontend MUST display a message like "Unable to reach the server. Please check your connection and try again."
- AND the form data MUST be preserved (not cleared)
- AND a retry option SHOULD be available

#### Scenario: Validation error (HTTP 400/422)

- GIVEN the user submits a case with missing required fields
- WHEN the API returns HTTP 422 with field-level errors
- THEN the frontend MUST map errors to specific form fields
- AND invalid fields MUST be highlighted with their error messages

#### Scenario: Authorization error (HTTP 403)

- GIVEN a user without admin privileges
- WHEN they attempt to create a case type via the API
- THEN the API MUST return HTTP 403
- AND the frontend MUST display "You do not have permission to perform this action"

#### Scenario: Not found error (HTTP 404)

- GIVEN a case with UUID "abc-123-def" has been deleted
- WHEN the frontend attempts to fetch it
- THEN the API MUST return HTTP 404
- AND the frontend MUST display "The requested case could not be found"
- AND the frontend SHOULD redirect to the case list

#### Scenario: Server error (HTTP 500)

- GIVEN an unexpected error occurs on the server
- WHEN the API returns HTTP 500
- THEN the frontend MUST display a generic error message: "An unexpected error occurred. Please try again later."
- AND the error SHOULD be logged to the browser console with details for debugging

---

### REQ-OREG-009: Cascade Behaviors

The system MUST define what happens to dependent entities when a parent entity is deleted or modified.

**Tier**: V1


#### Scenario: Delete a case with linked entities

- GIVEN case #2024-042 has 5 tasks, 3 roles, 1 result, and 2 decisions
- WHEN the user deletes case #2024-042
- THEN the system MUST either:
  - (a) Cascade delete all linked tasks, roles, results, and decisions, OR
  - (b) Prevent deletion and warn the user that dependent entities exist
- AND the system MUST NOT leave orphaned task/role/result/decision objects

#### Scenario: Delete a case type with linked type definitions

- GIVEN case type "Bezwaarschrift" (draft, no cases reference it) has 3 status types, 2 result types, and 2 role types
- WHEN the admin deletes the case type
- THEN all linked status types, result types, role types, property definitions, document types, and decision types MUST also be deleted (cascade)

#### Scenario: Delete a case type that is in use

- GIVEN case type "Omgevingsvergunning" is referenced by 10 active cases
- WHEN an admin attempts to delete the case type
- THEN the system MUST prevent the deletion
- AND the error message MUST indicate that the case type is in use by 10 cases

#### Scenario: Remove a status type with active cases

- GIVEN status type "Besluitvorming" is linked to case type "Omgevingsvergunning"
- AND 3 cases currently have status "Besluitvorming"
- THEN the system MUST prevent removal
- AND the error message MUST indicate that 3 cases are currently in this status

---

### REQ-OREG-010: Audit Trail Integration

All create, update, and delete operations on Procest objects MUST be captured in the audit trail, integrated via the `auditTrailsPlugin()` in the object store.

**Tier**: MVP


#### Scenario: Case creation is logged

- GIVEN user "jan.devries" creates case #2024-053
- THEN the audit trail MUST record: action, entity type, entity UUID, user, timestamp, and key field values

#### Scenario: Task status change is logged

- GIVEN user "jan.devries" changes task "Review documenten" from `active` to `completed`
- THEN the audit trail MUST record: action "status_changed", entity type "task", old value "active", new value "completed", user, and timestamp

#### Scenario: Audit trail is displayed on case detail

- GIVEN case #2024-042 has 15 audit events
- WHEN the user views the Activity Timeline section on the case detail
- THEN the events MUST be displayed in reverse chronological order
- AND each event MUST show: description, user, timestamp
- AND the timeline MUST be paginated or have a "Load more" option

---

### REQ-OREG-011: RBAC (Role-Based Access Control)

The system MUST enforce access control via OpenRegister's RBAC system. Configuration entities MUST be admin-only. Instance entities MUST be accessible to authorized users.

**Tier**: MVP


#### Scenario: Admin-only access to configuration entities

- GIVEN a non-admin user "jan.devries"
- WHEN Jan attempts to create, update, or delete a case type via the API
- THEN the system MUST return HTTP 403

#### Scenario: Regular user can create instance entities

- GIVEN a regular Nextcloud user "jan.devries"
- THEN Jan MUST be able to create cases, tasks, roles, results, and decisions on cases he has access to

#### Scenario: Nextcloud admin settings page requires admin

- GIVEN a non-admin user navigates to the Procest admin settings URL
- THEN the Nextcloud admin settings system MUST prevent access

---

### REQ-OREG-012: Performance and Eager Loading

The frontend MUST minimize API round-trips by fetching related entities efficiently.

**Tier**: MVP


#### Scenario: Case detail page loads all related data in parallel

- GIVEN the user opens case detail for case #2024-042
- THEN the frontend MUST fetch the following in parallel (not sequentially):
  - Case object (with case type, status references)
  - Tasks for the case
  - Roles for the case
  - Decisions for the case
  - Result for the case (if exists)
- AND the total load time MUST be under 3 seconds for a case with 10 tasks, 5 roles, 3 decisions

#### Scenario: Case type store pre-fetches on app initialization

- GIVEN the case list shows 20 cases referencing 4 different case types
- THEN the frontend MUST NOT make 20 individual API calls to resolve case type names
- AND the case type store SHOULD pre-fetch all case types on app initialization (small dataset, typically less than 20)

#### Scenario: My Work aggregation performance

- GIVEN the My Work view needs to display cases and tasks for the current user
- THEN the frontend MUST make exactly 2 API calls:
  - Cases with `?assignee=currentUser&status_ne=final`
  - Tasks with `?assignee=currentUser&status=available,active`
- AND the total load time MUST be under 2 seconds

#### Scenario: Pagination prevents loading too many objects

- GIVEN the case list could contain hundreds of cases
- THEN the default page size MUST NOT exceed 50
- AND the frontend MUST use pagination (not load all objects at once)

---

### REQ-OREG-013: ZGW API Layer

The system MUST provide ZGW-compliant API endpoints that map between ZGW Dutch field names and Procest's English field names, enabling interoperability with the Dutch government API ecosystem.

**Tier**: MVP


#### Scenario: ZGW Zaken API compliance

- GIVEN the `ZrcController.php` implements ZGW Zaken API (ZRC) endpoints
- THEN it MUST support standard CRUD operations on cases (zaken) using ZGW field names
- AND the `ZgwMappingService` MUST translate between ZGW Dutch names and internal English names
- AND business rules MUST be enforced via `ZgwZrcRulesService`

#### Scenario: ZGW Catalogi API compliance

- GIVEN the `ZtcController.php` implements ZGW Catalogi API (ZTC) endpoints
- THEN it MUST expose case types (zaaktypen), status types, result types, role types, and decision types via ZGW-compliant endpoints

#### Scenario: ZGW Besluiten API compliance

- GIVEN the `BrcController.php` implements ZGW Besluiten API (BRC) endpoints
- THEN it MUST support CRUD operations on decisions (besluiten)
- AND business rules MUST be enforced via `ZgwBrcRulesService`

#### Scenario: ZGW authentication

- GIVEN external systems connecting via ZGW APIs
- THEN the `ZgwAuthMiddleware` MUST validate JWT tokens per the ZGW API authentication standard

---

## Cross-Entity Reference Map

```
CaseType -----------------------------------------------------------+
|                                                                     |
+-- StatusType[]        (statusType.caseType -> caseType UUID)       |
+-- ResultType[]        (resultType.caseType -> caseType UUID)       |
+-- RoleType[]          (roleType.caseType -> caseType UUID)         |
+-- PropertyDefinition[] (propertyDefinition.caseType -> caseType)   |
+-- DocumentType[]      (documentType.caseType -> caseType UUID)     |
+-- DecisionType[]      (decisionType.caseType -> caseType UUID)     |
                                                                      |
Case ---------------------------------------------------------------+
|  case.caseType -> caseType UUID                                     |
|  case.status -> statusType UUID                                     |
|  case.result -> result UUID (optional)                              |
|  case.assignee -> Nextcloud user UID (optional)                     |
|  case.parentCase -> case UUID (optional, for sub-cases)            |
|                                                                     |
+-- Task[]              (task.case -> case UUID)                      |
|     task.assignee -> Nextcloud user UID (optional)                  |
|                                                                     |
+-- Role[]              (role.case -> case UUID)                      |
|     role.roleType -> roleType UUID                                  |
|     role.participant -> Nextcloud user UID or contact ref           |
|                                                                     |
+-- Result              (result.case -> case UUID, at most 1)        |
|     result.resultType -> resultType UUID                            |
|                                                                     |
+-- Decision[]          (decision.case -> case UUID)                  |
      decision.decisionType -> decisionType UUID (optional)           |
      decision.decidedBy -> Nextcloud user UID (optional)             |
```

---

## Summary: API Endpoint Patterns

| Entity | List | Get | Create | Update | Delete |
|--------|------|-----|--------|--------|--------|
| Case | `GET .../procest/case` | `GET .../procest/case/{id}` | `POST .../procest/case` | `PUT .../procest/case/{id}` | `DELETE .../procest/case/{id}` |
| Task | `GET .../procest/task` | `GET .../procest/task/{id}` | `POST .../procest/task` | `PUT .../procest/task/{id}` | `DELETE .../procest/task/{id}` |
| Role | `GET .../procest/role` | `GET .../procest/role/{id}` | `POST .../procest/role` | `PUT .../procest/role/{id}` | `DELETE .../procest/role/{id}` |
| Result | `GET .../procest/result` | `GET .../procest/result/{id}` | `POST .../procest/result` | `PUT .../procest/result/{id}` | `DELETE .../procest/result/{id}` |
| Decision | `GET .../procest/decision` | `GET .../procest/decision/{id}` | `POST .../procest/decision` | `PUT .../procest/decision/{id}` | `DELETE .../procest/decision/{id}` |
| CaseType | `GET .../procest/caseType` | `GET .../procest/caseType/{id}` | `POST .../procest/caseType` | `PUT .../procest/caseType/{id}` | `DELETE .../procest/caseType/{id}` |
| StatusType | `GET .../procest/statusType` | `GET .../procest/statusType/{id}` | `POST ...` | `PUT ...` | `DELETE ...` |
| (etc.) | (same pattern for all remaining schemas) | | | | |

Base URL: `/index.php/apps/openregister/api/objects`

---

### Current Implementation Status

**Core architecture implemented; individual patterns differ from spec in store approach.**

**Implemented (with file paths):**
- **Configuration file**: `lib/Settings/procest_register.json` exists, is valid JSON, conforms to OpenAPI 3.0.0, defines a register with app `procest`. Defines all schemas with `x-schema-org` and `x-zgw-equivalent` annotations (REQ-OREG-001).
- **Repair step**: `lib/Repair/InitializeSettings.php` calls `SettingsService::loadConfiguration()` which uses `ConfigurationService::importFromApp('procest')` from OpenRegister. Handles missing OpenRegister gracefully with warning. Is idempotent (REQ-OREG-002).
- **Settings service**: `lib/Service/SettingsService.php` with `loadConfiguration()`, `getSettings()`, `updateSettings()`, `autoConfigureAfterImport()`. Maps schema slugs to config keys via `SLUG_TO_CONFIG_KEY` constant (REQ-OREG-002).
- **Settings controller**: `lib/Controller/SettingsController.php` with routes `GET /api/settings` and `POST /api/settings` (REQ-OREG-003).
- **Settings store**: `src/store/modules/settings.js` -- Pinia store that fetches and saves settings with loading/error state tracking.
- **Object store**: `src/store/modules/object.js` -- uses `createObjectStore('object')` from `@conduction/nextcloud-vue` shared library. Single unified store (not per-entity stores). Provides CRUD, pagination, caching, `resolveReferences`, and `fetchSchema` via plugins: `filesPlugin()`, `auditTrailsPlugin()`, `relationsPlugin()` (REQ-OREG-005).
- **Frontend API patterns**: The object store queries OpenRegister via `/index.php/apps/openregister/api/objects/{register}/{schema}` endpoints (REQ-OREG-003).
- **ZGW API layer**: Full ZGW-compliant API controllers: `ZrcController.php` (Zaken), `ZtcController.php` (Catalogi), `DrcController.php` (Documenten), `BrcController.php` (Besluiten), `NrcController.php` (Notificaties), `AcController.php` (Autorisaties) with ZGW-to-English mapping via `ZgwMappingService` (REQ-OREG-013).
- **ZGW business rules**: `ZgwBusinessRulesService.php`, `ZgwZrcRulesService.php`, `ZgwZtcRulesService.php`, `ZgwDrcRulesService.php`, `ZgwBrcRulesService.php`.
- **ZGW auth middleware**: `lib/Middleware/ZgwAuthMiddleware.php` for JWT-based ZGW authentication.
- **Audit trail**: The `auditTrailsPlugin()` integrates with OpenRegister's audit trail. ZGW controllers expose `/audittrail` sub-routes (REQ-OREG-010).
- **Cross-entity references**: The `relationsPlugin()` supports resolving references. Case detail views resolve case types, status types, participants, and tasks (REQ-OREG-006).
- **Case detail parallel loading**: `src/views/cases/CaseDetail.vue` fetches case, tasks, roles, and related data (REQ-OREG-012).
- **Participants section**: `src/views/cases/components/ParticipantsSection.vue` resolves role types and participant display names via Nextcloud OCS API.
- **Result section**: `src/views/cases/components/ResultSection.vue` resolves result types.

**Not yet implemented or differs from spec:**
- **REQ-OREG-009: Cascade behaviors (V1)**: No cascade delete logic. Deleting a case does not automatically delete linked tasks/roles/results/decisions.
- **REQ-OREG-008: Concurrent modification (HTTP 409)**: Not implemented. No optimistic locking or conflict detection.
- **Reference integrity validation**: No server-side check that referenced UUIDs exist (e.g., task.case pointing to valid case).

### Standards & References

- **OpenAPI 3.0.0**: The register configuration file follows this format.
- **ZGW APIs (VNG Realisatie)**: Full ZGW-compliant API layer with ZRC, ZTC, DRC, BRC, NRC, and AC endpoints.
- **CMMN 1.1**: Task lifecycle states follow the CasePlanModel/HumanTask pattern.
- **Schema.org**: Entity type annotations in `procest_register.json`.
- **Common Ground**: Layered architecture with data in OpenRegister (information layer) and Procest as process layer.
- **Competitive reference**: Dimpact ZAC (PostgreSQL + 89 Flyway migrations), xxllnc Zaken (CQRS + event sourcing), ArkCase (JPA/Hibernate), Flowable (MyBatis + runtime/history tables).

### Specificity Assessment

- **Mostly implementable as-is.** The unified object store from `@conduction/nextcloud-vue` is the actual pattern rather than 12 individual stores.
- **ZGW API layer is a major feature** not previously covered in the spec -- now included as REQ-OREG-013.
- **Schema inventory expanded** to include all 27 schemas from `SLUG_TO_CONFIG_KEY`.
- **Open questions:**
  - Should cascade delete be implemented in the frontend (orchestrated deletes) or via OpenRegister (declarative cascade rules)?
  - How does ZGW field mapping interact with the OpenRegister schema definitions at storage time?
