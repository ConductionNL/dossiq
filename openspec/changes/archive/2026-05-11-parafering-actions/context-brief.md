# Context Brief: Parafering Actions

**App:** Procest — Case management, VTH, forms
**Spec:** parafering-actions
**Platform:** Nextcloud + OpenRegister

**Depends on:** parafeerroute-engine

## Dependency Specs (content)

These specs were already decided/implemented. Use them as context.

### parafeerroute-engine
## ADDED Requirements

### Requirement: Parafeerroute Schema Registration

The system SHALL register a `parafeerroute` schema in the Procest OpenRegister configuration with properties: name (string), caseType (reference), voorstelType (enum: dt_advies, collegeadvies, raadsvoorstel), steps (array of parafeerstap objects). Each parafeerstap SHALL have: order (integer), type (enum: advies, parafering, accordering), actor (string, user UID or group/role name), actorType (enum: user, group, role), mandatory (boolean).

**Feature tier**: V1
**Schema.org type**: `schema:HowTo`
**CMMN concept**: PlanItemDefinition with ordered HumanTasks

#### Scenario: Schema is available after app install

- **WHEN** the Procest app is installed or updated
- **THEN** the `parafeerroute` schema SHALL be registered in the Procest register via the repair step
- **AND** the schema SHALL enforce required properties: name, steps

### Requirement: Sequential Step Routing

The system SHALL execute parafeerroute steps in sequential order. Each step SHALL complete before the next step is activated.

**Feature tier**: V1

#### Scenario: Sequential step execution

- **WHEN** a voorstel is submitted for parafering with a 5-step route
- **THEN** the system SHALL activate step 1 first
- **AND** step 2 SHALL NOT be activated until step 1 is completed
- **AND** each actor SHALL receive a Nextcloud notification when their step is activated

#### Scenario: Step completion advances to next

- **WHEN** the actor at step 3 completes their action (paraferen or adviseren)
- **THEN** the voorstel currentStep SHALL advance to 4
- **AND** the step 4 actor SHALL receive a Nextcloud notification
- **AND** the voorstel updatedAt SHALL be refreshed

### Requirement: Admin Parafeerroute Configuration

The system SHALL provide an admin UI for creating and managing parafeerroutes. Routes SHALL be linkable to case types and voorstel types.

**Feature tier**: V1

#### Scenario: Create a new parafeerroute

- **WHEN** the beheerder navigates to admin settings and opens the "Parafeerroutes" tab
- **THEN** the beheerder SHALL be able to create a new route with a name
- **AND** the beheerder SHALL be able to add steps with: step type (advies/parafering/accordering), actor type (user/group/role), actor selection, mandatory flag
- **AND** the beheerder SHALL be able to reorder steps via drag-and-drop

#### Scenario: Link route to case type

- **WHEN** the beheerder creates a parafeerroute
- **THEN** the beheerder SHALL be able to link it to a case type and voorstel type
- **AND** when a steller creates a voorstel of that type on a case of that type, the linked route SHALL be loaded as default

#### Scenario: Edit existing parafeerroute

- **WHEN** the beheerder edits an existing parafeerroute that is not in active use
- **THEN** the beheerder SHALL be able to add, remove, or reorder steps
- **AND** voorstellen already using this route SHALL NOT be affected (they keep a snapshot of the route at submission time)

### Requirement: Override Route on Specific Voorstel

The system SHALL allow authorized users to modify the parafeerroute on a specific voorstel (skip steps, add ad-hoc steps) with mandatory reason.

**Feature tier**: V1

#### Scenario: Skip a step

- **WHEN** an authorized manager skips the "Adviseur vakinhoud" step on a specific voorstel
- **THEN** the step SHALL be marked as skipped
- **AND** a mandatory reason text SHALL be recorded
- **AND** the audit trail SHALL record: "Stap overgeslagen: [step name] door [manager], reden: [text]"

#### Scenario: Add ad-hoc step

- **WHEN** the steller adds an ad-hoc advisory step "Financieel adviseur" between steps 2 and 3
- **THEN** the route for this voorstel SHALL be adjusted: existing steps after insertion point SHALL be renumbered
- **AND** the audit trail SHALL record: "Stap toegevoegd: [step name] door [user]"


## Features (3 total, sorted by market demand)

### Contract lifecycle management with creation, review, approval, and e-signature workflow
**demand: 1901** (631 tender mentions) | Category: other

### Electronic signature integration for digital contract execution workflow
**demand: 921** (305 tender mentions) | Category: integration

### E-signature workflow
**demand: 258** (74 tender mentions) | Category: other

## User Stories

(No user stories linked to this spec. Generate from the features above.)

## Customer Journeys

(No journeys linked. Infer from stakeholders and features above.)

## Stakeholders

(No stakeholders linked. Infer from the features and user stories above.)

## Other App Entities (do NOT redefine)

abonnement, adviesAanvraag, advisoryReport, appealDecision, case, caseDocument, caseObject, caseProperty, caseType, catalogus, customerContact, decision, decisionDocument, decisionType, dispatch, document, documentLink, documentType, handhavingsactie, hearingSession, inspectieChecklist, inspectieRapport, kanaal, mapLayer, objection, parafeeractie, parafeerroute, propertyDefinition, result, resultType, role, roleType, statusRecord, statusType, task, usageRights, voorstel, workflowTemplate, zaaktypeInformatieobjecttype

## Company-Wide Architecture Rules (21 ADRs)

These rules are MANDATORY for all Conduction apps.

### ADR-001-data-layer
- ALL domain data → OpenRegister objects. NO custom Entity/Mapper for domain data.
- App config → `IAppConfig`. NOT OpenRegister.
- Cross-entity references: OpenRegister relations (register+schema+objectId). NO foreign keys.
  MUST NOT store foreign keys or embed full objects.

### Schema standards

- Schemas: PascalCase, schema.org vocabulary, explicit types + required flags + description field.
- MUST NOT invent custom property names when a schema.org equivalent exists.
- Contact schemas MUST align with vCard properties (fn, email, tel, adr).
- Dutch government fields SHOULD use a mapping layer translating between international standards
  and Dutch specs — do not hardcode Dutch field names as primary.
- Schema changes that remove or rename properties are BREAKING. Adding optional properties is non-breaking.

### Register templates

- Location: `lib/Settings/{app}_register.json` (OpenAPI 3.0 + `x-openregister` extensions).
- Three template categories:
  - **App configuration** — define data models (schemas/registers/views/mappings).
    Mark with `x-openregister.type: "application"`.
  - **Mock data** — fictional but realistic seed data for dev/test.
    Mark with `x-openregister.type: "mock"`.
  - **Government standards** — aligned to Dutch API specs (BAG, BRP, KVK, DSO).
- Import mechanism: `ConfigurationService::importFromApp(appId, data, version, force)` →
  `ImportHandler::importFromApp()`. Called from repair step or `SettingsLoadService`.
- Idempotency: re-importing with `force: false` MUST NOT create duplicates. Match by slug
  using `ObjectService::searchObjects` with `_rbac: false` and `_multitenancy: false`.
  Use `version_compare` for skip logic.

### Seed data

Apps that store data in OpenRegister are empty on first install. An empty app cannot be
meaningfully tested — there are no objects to view, search, filter, or interact with.
This blocks both automated browser testing and manual QA. The Loadable Register Template
pattern (see Register templates above) already supports seed data via `components.objects[]`
with the `@self` envelope.

**Requirements:**

- Every app using OpenRegister MUST include 3-5 realistic objects per schema in
  `lib/Settings/{app}_register.json`.
- Use `@self` envelope: `{ "@self": { "register": ..., "schema": ..., "slug": ... }, ...properties }`.
  Register/schema MUST match keys; slug is unique human-readable identifier for matching.
- Use general organisation data (municipality, consultancy, travel agency, non-profit) —
  NOT context-specific. Varied, realistic field values.
- Mock data quality: real Dutch street names, valid postcodes (`[1-9][0-9]{3}[A-Z]{2}`),
  correct municipality/KVK codes, BSNs that pass 11-proef. Fictional but distinguishable from real.
- Cross-register consistency: BRP→BAG, KVK→BAG, DSO→BAG references must be valid.
- Loaded on install alongside schemas via same `importFromApp()` pipeline.
- MUST be idempotent — re-importing skips existing objects matched by slug.

**In OpenSpec artifacts:**

- **In design.md**: MUST include a Seed Data section when change introduces/modifies schemas —
  define seed objects per schema with concrete field values and related items (files, notes, tasks, contacts).
- **In tasks.md**: MUST include a seed data generation task when change introduces/modifies schemas.

**Exceptions** (no seed data required):

- **nldesign** — has no OpenRegister schemas.
- **ExApp sidecar wrappers** (openklant, opentalk, openzaak, valtimo, n8n-nextcloud) — proxy
  external services and do not use OpenRegister.
- **nextcloud-vue** — shared library, no seed data applicable.
- Changes that only modify frontend components or non-schema backend logic (e.g., settings,
  permissions) do not require seed data.

**Limitations:** OpenRegister's `ImportHandler` currently supports only flat seed objects.
Related items (files, notes, tasks, contacts) linked through the relation system are tracked
on the product roadmap. Until then, seed data is limited to object properties defined in schemas.

### Deduplication check

- Before proposing new capability: search `openspec/specs/` and `openregister/lib/Service/` for overlap
  with ObjectService, RegisterService, SchemaService, ConfigurationService, and shared Vue components.
- If similar capability exists: MUST reference it and explain why new code is needed rather than extending.
- Proposals duplicating existing functionality without justification MUST be rejected.
- **In design.md**: MUST include a "Reuse Analysis" section listing existing OpenRegister services leveraged.
- **In tasks.md**: MUST include a "Deduplication Check" task verifying no overlap — document findings
  even if "no overlap found".

### Schema migrations

- Breaking schema changes → new migration in repair step. NEVER modify existing migrations.

### OpenRegister + @conduction/nextcloud-vue — DO NOT REBUILD

The platform provides 258+ backend methods and 69+ frontend components. Apps ONLY build
custom logic for domain-specific business rules. Everything below is provided for FREE.

**CRUD & Data Management** (use ObjectService + CnIndexPage + CnDetailPage):
- Single & bulk create, read, update, delete — `ObjectService.saveObject()`, `deleteObject()`
- List with pagination, sorting, filtering — `ObjectService.findAll()` + `CnDataTable`
- Schema-driven forms — `CnFormDialog` (auto-generates from schema) or `CnAdvancedFormDialog`
- Detail views — `CnDetailPage` with `CnDetailGrid`, `CnDetailCard` sections
- Record merging/deduplication — `ObjectService.mergeObjects()`
- Object locking — `ObjectService.lockObject()` / `unlockObject()`

**Import & Export** (use ImportService/ExportService + CnMassImportDialog/CnMassExportDialog):
- CSV, Excel, JSON import with intelligent field mapping — `ImportService`
- CSV, Excel, JSON export with column selection — `ExportService`
- Bulk import with validation and progress — `CnMassImportDialog`
- Filtered export with format picker — `CnMassExportDialog`
- NO custom import dialogs, parsers, upload handlers, or export controllers

**Search & Discovery** (use IndexService + CnFilterBar + CnFacetSidebar):
- Full-text search with field weighting — `IndexService`
- Faceted navigation with counts — `FacetBuilder` + `CnFacetSidebar`
- Semantic search with embeddings — `VectorizationService`
- Hybrid search (keyword + semantic) — automatic
- Search analytics — `SearchTrailService` (popular terms, activity)
- NO custom search endpoints, query builders, or search pages

**File Management** (use FileService + CnObjectSidebar):
- Upload (single/multipart), download, share links — `FileService`
- File tagging, public/private toggle — `FileService`
- Bulk download as ZIP — `createObjectFilesZip()`
- Text extraction from PDFs/Office docs — `TextExtractionService`
- File tab in object sidebar — `CnObjectSidebar` → `CnFilesTab`
- NO custom file upload components, file controllers, or download handlers

**Audit & Compliance** (use AuditTrailService + CnObjectSidebar):
- Full change tracking with before/after snapshots — automatic
- Audit trail tab — `CnObjectSidebar` → `CnAuditTrailTab`
- GDPR data subject access requests — `inzageverzoek()`, `verwerkingsregister()`
- Audit export and analytics — `AuditTrailController`
- NO custom audit logging, change tracking, or compliance controllers

**Dashboard & Analytics** (use CnDashboardPage + CnChartWidget + CnStatsBlock):
- Drag-drop widget dashboard — `CnDashboardPage` with GridStack
- KPI cards — `CnKpiGrid`, `CnStatsBlock`, `CnStatsPanel`
- Charts (line/bar/pie/donut) — `CnChartWidget` (ApexCharts)
- Data tables as widgets — `CnTableWidget`
- Editable data grids — `CnObjectDataWidget`
- NO custom dashboard layouts, chart components, or KPI cards

**Forms & Dialogs** (use CnFormDialog + schema-driven generation):
- Auto-generated create/edit forms — `CnFormDialog` reads schema → generates fields
- JSON/metadata editing — `CnAdvancedFormDialog` with Properties/Data/Metadata tabs
- Schema editor — `CnSchemaFormDialog`
- Delete/Copy/Mass operations — `CnDeleteDialog`, `CnCopyDialog`, `CnMassDeleteDialog`
- NO custom form components, validation logic, or dialog wrappers

**Navigation & Pagination** (use CnPagination + CnActionsBar + useListView):
- Pagination control with size selector — `CnPagination`
- Action bar (add, search, toggle views) — `CnActionsBar`
- List state management — `useListView` composable (handles search, filter, sort, page)
- Detail state management — `useDetailView` composable
- NO custom pagination logic, debounced search, or list state management

**Authorization & RBAC** (use AuthorizationService + PropertyRbacHandler):
- Role-based access control — `AuthorizationService`
- Field-level permissions — `PropertyRbacHandler`
- Object-level restrictions — `PermissionHandler`
- Authorization audit — `AuthorizationAuditService`
- NO custom permission checks, role systems, or access control middleware

**Webhooks & Events** (use WebhookService):
- Create, test, retry webhooks — `WebhookService`
- CloudEvents format — automatic
- Event subscriptions — selective per schema/action
- NO custom webhook controllers or event dispatchers

**Notifications & Activity** (use NotificationService + ActivityService):
- Nextcloud notifications — `NotificationService`
- Activity feed — `ActivityService`
- Calendar events — `CalendarEventService`
- Deck/Kanban cards — `DeckCardService`

**Store & State** (use createObjectStore + plugins):
- Object stores — `createObjectStore(name)` generates Pinia CRUD store
- Store plugins: `auditTrails`, `files`, `lifecycle`, `relations`, `search`, `selection`
- Column/field/filter generation from schema — `columnsFromSchema()`, `fieldsFromSchema()`
- NO custom Pinia stores for CRUD, Vuex, or manual API call management

**Chat & AI** (use ChatService):
- Multi-turn conversation — `ChatService`
- RAG-based knowledge retrieval — `ContextRetrievalHandler`
- LLM response generation — `ResponseGenerationHandler`

**Data Retention & Archival** (use ArchivalService):
- Legal hold — `LegalHoldService`
- Destruction schedules — `DestructionService`
- Retention policies — `RetentionService`

**Semantic & Hybrid Search** (use SolrController + SettingsController):
- Semantic search via vector embeddings — `SettingsController.semanticSearch()`
- Hybrid search (keyword + semantic combined) — `SolrController.hybridSearch()`
- Vector embedding generation — `VectorizationService`
- NO custom search algorithms — configure via OpenRegister settings

**GraphQL API** (use GraphQLController):
- Query objects across schemas via GraphQL — `GraphQLController.execute()`
- Alternative to REST for complex cross-entity queries

**Organization / Multi-Tenancy** (use OrganisationController):
- Organization CRUD — `OrganisationController`
- Tenant-scoped data isolation — automatic via `TenantLifecycleService`
- NO custom multi-tenancy logic

**Task & Workflow Management** (use TasksController + WorkflowEngineController):
- Task creation and tracking — `TasksController`
- Workflow orchestration — `WorkflowEngineRegistry`
- Scheduled workflows — `ScheduledWorkflowController`
- NO custom task/workflow systems

**Text Extraction** (use FileTextController):
- Extract text from PDFs and Office docs — `TextExtractionService`
- Entity recognition (PII detection) — `EntityRecognitionHandler`
- Content anonymization — automatic

**Timeline & Stages** (use CnTimelineStages):
- Workflow progression visualization — `CnTimelineStages` component
- Stage tracking with status colors

### What apps SHOULD build (custom business logic only):
- External API integrations (SAP, Peppol, TenderNed, etc.)
- PDF/document generation with business-specific templates
- Workflow triggers and business rules specific to the domain
- Notification dispatch with app-specific event types
- Custom settings pages with app-specific configuration
- Background jobs for domain-specific processing

### ADR-002-api
- URL pattern: `/index.php/apps/{app}/api/{resource}` — lowercase plural, hyphens.
- Methods: GET=read, POST=create, PUT=update, DELETE=remove. No custom methods.
- Pagination: support `_page` + `_limit`. Response includes `total`, `page`, `pages`.
- Errors: appropriate HTTP status + `message` field. NO stack traces in responses.
- Auth: Nextcloud built-in only. NO custom login/session/token flows.
- Public endpoints: annotate `#[PublicPage]` + `#[NoCSRFRequired]`. Register CORS OPTIONS route.

### ADR-003-backend
- **Controller → Service → Mapper** (strict 3-layer). Controllers NEVER call mappers directly.
- Controllers: thin (<10 lines/method). Routing + validation + response only.
- Services: ALL business logic. Stateless — no instance state between requests.
- Mappers: DB CRUD only. No business logic.
- DI: constructor injection with `private readonly`. NO `\OC::$server` or static locators.
- Entity setters: POSITIONAL args only. `$e->setName('val')` — NEVER `$e->setName(name: 'val')`.
  (`__call` passes `['name' => val]` but `setter()` uses `$args[0]`.)
- Routes: `appinfo/routes.php`. Specific routes BEFORE wildcard `{slug}` routes.
- Config: `IAppConfig` with sensitive flag for secrets. NEVER read DB directly.
- Lifecycle: schema init via repair steps (`IRepairStep`), background via job queue, events via dispatcher.
- **Spec traceability**: every class and public method MUST have `@spec` PHPDoc tag(s) linking to
  the OpenSpec change that caused it: `@spec openspec/changes/{name}/tasks.md#task-N`.
  Multiple `@spec` tags allowed (code touched by multiple changes). File-level `@spec` in header docblock.
  This enables: code → docblock → spec traceability alongside code → git blame → commit → issue → spec.

### ADR-004-frontend
- **Vue 2 + Pinia + @nextcloud/vue + @conduction/nextcloud-vue**. NO Vuex. Options API only.
- State: Pinia stores in `src/store/modules/`. Use `createObjectStore` for OpenRegister CRUD.
- API calls: `axios` from `@nextcloud/axios` — auto-attaches CSRF token. NEVER raw `fetch()` for mutations.
  Loading state with `try/finally`.
- Translations: ALL user-visible strings via `t(appName, 'text')`. NO hardcoded strings.
  Translation keys MUST be English — Dutch translations go in `l10n/nl.json`.
- CSS: ONLY Nextcloud CSS variables (`var(--color-primary-element)`, etc.). NO hardcoded colors.
  NEVER reference `--nldesign-*` directly — nldesign app handles theming.
- Router: history mode, base `generateUrl('/apps/{app}/')`. Requires matching PHP routes in `routes.php`.
  Deep link URL templates MUST match the router mode — use path format (`/apps/{app}/entities/{uuid}`),
  NOT hash format (`/apps/{app}/#/entities/{uuid}`).
- OpenRegister dependency: settings returns `openRegisters` (bool) + `isAdmin`.
  Show empty state if OR missing. NEVER use `OC.isAdmin` — get from backend.
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog` (WCAG, theming).
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API or store.
- EVERY `await store.action()` call MUST be wrapped in `try/catch` with user-facing error feedback.
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports all
  NC components plus Conduction components. This ensures consistent theming and component versions.
- EVERY component used in `<template>` MUST be imported AND registered in `components: {}`.
  Vue 2 silently renders unknown elements — missing imports cause invisible runtime failures.

### NL Design System

- ALL UI components MUST use CSS custom properties from NL Design System tokens.
- MUST support theme switching via nldesign app's token sets.
- MUST meet WCAG AA compliance: keyboard-navigable, associated labels, color is not the sole
  method of conveying information.
- SHOULD work on 320px–1920px viewports; critical functionality MUST work at 768px (tablet).
- Exceptions: PDF generation (docudesk), admin-only screens (simpler styling allowed).

### @conduction/nextcloud-vue — ALWAYS check before building custom

**Pages & Layout:**
  `CnIndexPage` (schema-driven list+CRUD) | `CnDetailPage` (detail+sidebar) |
  `CnPageHeader` (title+icon) | `CnActionsBar` (add+search+toggle)

**Data Display:**
  `CnDataTable` (sortable+paginated) | `CnCardGrid` + `CnObjectCard` (card views) |
  `CnDetailGrid` (label-value pairs) | `CnFilterBar` (search+filters) |
  `CnFacetSidebar` (faceted filters) | `CnPagination` | `CnCellRenderer` (type-aware)

**Forms & Dialogs:**
  `CnFormDialog` (schema-driven create/edit) | `CnAdvancedFormDialog` (properties+JSON+metadata) |
  `CnSchemaFormDialog` (JSON Schema editor) | `CnTabbedFormDialog` (tabbed form framework) |
  `CnDeleteDialog` | `CnCopyDialog`

**Mass Actions:**
  `CnMassDeleteDialog` | `CnMassCopyDialog` | `CnMassExportDialog` (CSV/JSON/XML) |
  `CnMassImportDialog` (upload+summary) | `CnMassActionBar` (floating selection bar)

**Dashboard & Widgets:**
  `CnDashboardPage` (GridStack drag-drop layout) | `CnDashboardGrid` (layout engine) |
  `CnWidgetWrapper` (widget shell) | `CnWidgetRenderer` (NC Dashboard API v1/v2) |
  `CnChartWidget` (ApexCharts: area/line/bar/pie/donut/radial) |
  `CnTableWidget` (data table widget) | `CnTileWidget` (quick-access tile) |
  `CnInfoWidget` (label-value grid) | `CnKpiGrid` (responsive KPI layout) |
  `CnStatsBlock` (metric card) | `CnStatsPanel` (stats sections) | `CnProgressBar` |
  `CnObjectDataWidget` (schema-driven editable data grid, inline edit + save via objectStore) |
  `CnObjectMetadataWidget` (read-only object metadata display)

**UI Elements:**
  `CnStatusBadge` | `CnEmptyState` | `CnIcon` (MDI) | `CnCard` | `CnDetailCard` |
  `CnRowActions` | `CnTimelineStages` (workflow progression) |
  `CnUserActionMenu` (user context menu) | `CnJsonViewer` (CodeMirror)

**Detail Sidebar:**
  `CnObjectSidebar` (Files/Notes/Tags/Tasks/Audit tabs) | `CnIndexSidebar` |
  `CnNotesCard` (inline notes) | `CnTasksCard` (inline tasks)

**Settings:**
  `CnSettingsSection` + `CnVersionInfoCard` (MUST be first on admin pages) |
  `CnSettingsCard` | `CnConfigurationCard` | `CnRegisterMapping`
  User settings: `NcAppSettingsDialog` (NOT `NcDialog`)

**Composables:**
  `useListView` (search/filter/sort/pagination) | `useDetailView` (load/edit/delete) |
  `useSubResource` (related items) | `useDashboardView` (widgets/layout/edit)

**Store Plugins:**
  `auditTrailsPlugin` | `relationsPlugin` | `filesPlugin` | `lifecyclePlugin` |
  `selectionPlugin` | `searchPlugin` | `registerMappingPlugin`

**Utilities:**
  `columnsFromSchema()` | `filtersFromSchema()` | `fieldsFromSchema()` |
  `formatValue()` | `buildHeaders()` | `buildQueryString()`

### Page Construction Patterns (follow these recipes)

**App.vue:** `NcContent` → 3 states: loading (`NcLoadingIcon`), no-OpenRegister (`NcEmptyContent`),
  ready (`MainMenu` + `NcAppContent` + `router-view` + optional `CnIndexSidebar`).
  Inject `sidebarState` for child components. `created()` calls `initializeStores()`.

**MainMenu:** `NcAppNavigation` with `NcAppNavigationItem` per route (icon + name + `:to`).
  Footer: `NcAppNavigationSettings` (gear foldout) with admin/config nav items.
  Settings item emits `@click="$emit('open-settings')"` — opens `NcAppSettingsDialog` modal.
  Do NOT route to `/settings` — in-app settings is a modal overlay, not a page.

**Dashboard:** `CnDashboardPage` with `CnStatsBlock` KPIs (4 cards: open/overdue/value/completed),
  status distribution chart, "My Work" list (grouped: overdue → due this week → rest).
  Fetch all collections in parallel via `Promise.all`. Widget templates via `#widget-{id}` slots.

**Index page:** `CnIndexPage` with `useListView(entityType, { sidebarState, objectStore })`.
  Inject sidebarState. Row click → `$router.push({ name: 'EntityDetail', params: { id } })`.
  Add button → new entity detail with id='new'.

**Detail page:** Two modes — edit (form component) / view (`CnDetailPage` + `CnDetailCard` sections).
  Header actions: Edit + Delete buttons. Related entities in table inside `CnDetailCard`.
  Props: `entityId` from route. `isNew = entityId === 'new'`. Sidebar via `CnObjectSidebar`.
  **Relations:** Every entity referenced in the spec MUST have a `CnDetailCard` section.
  Use `fetchUsed` for reverse lookups (find objects that reference THIS entity) and
  `fetchUses` for forward lookups (find objects THIS entity references).
  If the spec lists a "linked X section", it MUST be implemented — not deferred or stubbed.

**Settings — two surfaces, never a route:**
  *Admin settings* (`/settings/admin/{appid}`): `AdminRoot.vue` rendered by `settings.js` entry point,
  registered via `AdminSettings.php`. Layout: `CnVersionInfoCard` (FIRST) → `CnRegisterMapping` →
  `CnSettingsSection` per feature. Load via `GET /api/settings`, save via `POST /api/settings`.
  *In-app settings*: `UserSettings.vue` wrapping `NcAppSettingsDialog` — opened as a modal from the
  gear menu (`@open-settings` event on MainMenu), handled in `App.vue` with `:open` / `@update:open`.
  Do NOT create a `/settings` route. Do NOT create a standalone `SettingsView.vue` page component.

**Router:** Flat routes (no nesting), all named, props via arrow function for params.
  Routes: `/` (Dashboard), `/{entities}` (list), `/{entities}/:id` (detail).
  No `/settings` route — settings is a modal (see Settings section above).

**Store init:** `initializeStores()` in `store/store.js` — fetches settings, then calls
  `objectStore.registerObjectType(name, schemaSlug, registerSlug)` for each entity.
  Object store uses `createObjectStore` with plugins (files, auditTrails, relations).
  Settings store: Pinia `defineStore` with `fetchSettings()` and `saveSettings()`.

### ADR-005-security
- Auth: Nextcloud built-in ONLY. NO custom login, sessions, tokens, password storage.
- Admin check: `IGroupManager::isAdmin()` on BACKEND. Frontend-only checks = vulnerability.
- Per-object authorization (IDOR prevention): every mutation endpoint that operates on a specific
  object MUST check that the authenticated user owns, is in the group of, or is admin for THAT
  object — not just that they are logged in. `#[NoAdminRequired]` opens the endpoint to all users;
  without a per-object check, any user can modify any object by guessing its ID.
  Pattern: fetch object → extract `assigneeUserId`/`assigneeGroupId`/`createdBy` → check
  (owner OR in group OR admin) → throw `OCSForbiddenException` if none apply. Extract into a
  reusable `authorizeXxx(object, user)` service method, called from every PUT/POST/DELETE.
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable).
- Identity: always derive from `IUserSession` on backend — NEVER trust frontend-sent user IDs or display names.
- Nextcloud endpoint defaults: NO annotation = admin-only. Non-admin endpoints (agent/staff actions)
  MUST have `#[NoAdminRequired]` attribute. Pair every `#[NoAdminRequired]` with a per-object auth
  check — never trust the session alone for mutation.
- Input validation: all user-supplied strings that flow into URLs (query params, path segments)
  MUST be URL-encoded (`encodeURIComponent` in Vue/JS, `rawurlencode` in PHP). Email Message-IDs,
  file names, and free-text fields commonly contain `<`, `>`, `/`, `@`, `&` which break unencoded.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.
- Error messages: use static, generic messages (`'Operation failed'`, `'Not authorized'`) — NEVER
  return `$e->getMessage()` to clients. Log the real error server-side with `$this->logger->error()`.
- Test collections: NEVER commit default credentials — use env variable placeholders.

### ADR-006-metrics
- Every app: `GET /api/metrics` (Prometheus text, admin auth) + `GET /api/health` (JSON, public).
- Metric names: `{app}_` prefix. MUST include `{app}_health_status` and `{app}_info`.
- Health check MUST verify OpenRegister connectivity (for apps that depend on it).

### ADR-007-i18n
# ADR-007: Internationalization (i18n)

## Status
Accepted

## Context
All Conduction Nextcloud apps serve Dutch government users but must support multiple languages. We need a consistent approach to internationalization across all apps.

## Decision

### Primary Language: English
- **English (en) is the source/primary language** for all code and translation keys.
- All `t()` keys and `$this->l10n->t()` strings MUST be written in English.
- `l10n/en.json` is the identity-mapped source file (key == value).
- Hardcoded Dutch strings in code MUST be converted to English keys with Dutch translations in `nl.json`.

### Sentence Case for All UI Strings
- All translation keys and user-facing strings MUST use **sentence case**: only the first word is capitalized.
- Correct: `"Add directory"`, `"No results found"`, `"Delete selected"`, `"Save configuration"`
- Wrong (title case): `"Add Directory"`, `"No Results Found"`, `"Delete Selected"`
- Wrong (all lowercase): `"add directory"`, `"no results found"`
- **Exceptions** that keep their capitalization:
  - Proper nouns and product names: `"OpenRegister"`, `"Nextcloud"`, `"GitHub"`, `"DocuDesk"`
  - Acronyms: `"API"`, `"URL"`, `"PDF"`, `"SOLR"`, `"JSON"`, `"RBAC"`, `"OAS"`
  - Single-word strings still start with a capital: `"Delete"`, `"Search"`, `"Save"`

### Required Languages
- Minimum: English (en) + Dutch (nl) translations.
- `l10n/en.json` and `l10n/nl.json` MUST exist in every app with a UI.
- Both files MUST contain exactly the same keys, with zero gaps.

### Frontend Translation
- JS: `t(appName, 'key')` for singular, `n(appName, 'singular', 'plural', count)` for plurals.
- `Vue.mixin({ methods: { t, n } })` for Options API components.
- `<script setup>` components MUST import `t` directly from `@nextcloud/l10n` (mixin does not apply).

### Backend Translation
- PHP: `$this->l10n->t('key')` for user-facing messages in JSONResponse.
- Controllers returning user-facing messages MUST inject `OCP\IL10N`.
- Log messages, internal exceptions, and database values are NOT translated.

### API and Data
- API field names: always English (language-neutral data layer).
- Date/number formatting: respect user locale via Nextcloud core.
- Each app with OpenRegister: define `register-i18n` spec listing translatable fields.

### Shared Component Library (@conduction/nextcloud-vue)
- The shared library does NOT translate internally — it accepts pre-translated strings via props.
- Components have English defaults for all label/text props (e.g., `addLabel="Add"`, `cancelLabel="Cancel"`).
- Consumer apps are responsible for passing `t()` results as prop values.
- The library lists `@nextcloud/l10n` as a peer dependency, not a direct dependency.

## Consequences
- All apps maintain two translation files that must stay in sync.
- Dutch strings used as translation keys (e.g., `t('app', 'Besluiten')`) are a violation — the English equivalent must be the key.
- Title case in translation keys (e.g., `"Add Directory"`) is a violation — use sentence case (`"Add directory"`).
- New features must include both `en.json` and `nl.json` entries before merging.

### ADR-008-testing
- Every new PHP service/controller → PHPUnit tests in `tests/Unit/` (≥3 methods).
- Every new Vue component → test file (if test framework exists).
- Every new API endpoint → Newman/Postman collection in `tests/integration/`.
- Every spec scenario → browser test (GIVEN/WHEN/THEN verified via Playwright).
- All tests MUST pass in `composer check:strict`.
- Integration tests MUST cover error paths (403, 401, 400) — not just happy path (200).
- Test collections: use env variable placeholders for credentials — NEVER hardcode defaults.

### Smoke testing (before opening PR)

After implementing, verify your code actually works — quality gates catch lint/types, not logic:

1. Call each new API endpoint with `curl` — verify response shape and status code
2. Test at least one error path per endpoint (missing param, wrong auth, invalid input)
3. If the spec says a feature is deferred, verify it is NOT registered/enabled
4. If tasks.md marks a task `[x]`, verify it is fully implemented — not a stub or TODO

### Task completeness verification

Before marking a task `[x]` in tasks.md or opening a PR:
- Re-read every task in tasks.md
- For each `[x]` task, verify the implementation exists AND works — not a placeholder
- Stub components, empty relation sections, and TODO comments are NOT complete
- If a task cannot be completed, leave it `[ ]` and explain in the PR description

### ADR-009-docs
- Every user-facing feature → docs in `docs/` with screenshots from running app.
- English primary, Dutch recommended. Update docs when behavior changes.

### ADR-010-nl-design
- ALL UI: CSS custom properties from NL Design System tokens. NO hardcoded colors, fonts, spacing.
- Theme switching: support `nldesign` app's token sets (Rijkshuisstijl, Utrecht, municipality-specific).
- Components: `@nextcloud/vue` primary. Custom components styled via NL Design tokens only.
- Scoped styles: ALL `<style>` blocks MUST use `scoped` attribute.
- WCAG AA mandatory: keyboard-navigable, labelled forms, color not sole conveyor, alt text on images.
- Responsive: work from 320px to 1920px. Critical features accessible at 768px.
- Specs: reference token names ("primary action color") NOT hex values. Include a11y verification in ACs.
- Exception: PDF generation (docudesk) may use fixed dimensions. Admin screens MAY simplify but MUST meet WCAG AA.

### ADR-011-schema-standards
- schema.org types/properties as primary vocabulary (`schema:Person`, `schema:Organization`, `schema:Event`).
- Contact schemas: align with vCard properties (`fn`, `email`, `tel`, `adr`).
- Dutch government fields: mapping layer translating between international standards and Dutch APIs (VNG, ZGW).
- NO custom property names when schema.org equivalent exists.
- Relations: OpenRegister relation mechanism (register + schema + objectId). NO foreign keys or embedded objects.
- Versioning: removing/renaming properties = BREAKING → migration via repair step. Adding optional = non-breaking.
- Specs MUST define data models using schema.org vocabulary; design docs MUST include schema definitions with types, required flags, relations.
- Exception: app-specific workflow states (pipeline stages, process statuses) MAY use custom vocabularies.

### ADR-012-deduplication
- Before proposing new capability: search OpenRegister specs + services for overlap. Reference + justify if similar exists.
- Design docs MUST include "Reuse Analysis" listing which OpenRegister services are leveraged.
- If logic could benefit other apps → propose adding to OpenRegister core, not app-specific.
- Tasks MUST include "Deduplication Check" verifying no overlap with:
  ObjectService, RegisterService, SchemaService, ConfigurationService, shared specs, @conduction/nextcloud-vue.
- Document findings even if "no overlap found".
- Exception: OpenRegister checks internal duplication only. nldesign checks token sets. nextcloud-vue checks own components.

### ADR-013-container-pool
# ADR-013: Unified Container Pool

**Status:** accepted
**Date:** 2026-04-12

## Context

Specter (intelligence/research) and Hydra (build/review/merge) both run LLM workloads in Docker containers. Today they operate independently: Hydra spins up builder/reviewer/security containers on demand, Specter has a separate `run_llm_containers.sh` wrapper. Both compete for the same Claude Max rate limits.

We want to unify these into a **single priority-scheduled container pool** so that:
- Critical work (bugfixes, reviews) preempts lower-priority work (discovery, research)
- A fixed number of containers (e.g. 10) run continuously, pulling from a shared queue
- Token rotation and rate limit recovery happen at the pool level, not per-script
- Adding a new workload type (audit, spec generation, test) is just a new queue entry

## Decision

### Container types (priority order)

| Priority | Type | Source | Container image | Model | Fallback |
|----------|------|--------|-----------------|-------|----------|
| 1 | **code-review** | Hydra: PR code review + in-container fixes | `hydra-reviewer` | sonnet | opus |
| 2 | **security-review** | Hydra: PR security review + in-container fixes | `hydra-security` | sonnet | opus |
| 3 | **applier** | Hydra: binary go/no-go gate (no fix authority) | `hydra-applier` | sonnet | opus |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | haiku | — |
| 5 | **audit** | Hydra: codebase audit | `hydra-builder` | sonnet | opus |
| 6 | **spec-generation** | Specter: push_spec_pipeline | `specter-llm-worker` | sonnet | haiku |
| 7 | **schema-synthesis** | Specter: generate/dedup schemas | `specter-llm-worker` | haiku | — |
| 8 | **classification** | Specter: classify/redistribute features | `specter-llm-worker` | haiku | — |
| 9 | **translation** | Specter: translate requirements | `specter-llm-worker` | haiku | — |
| 10 | **discovery** | Specter: research, feature extraction | `specter-llm-worker` | haiku | — |

**No-loop policy (openspec/changes/no-loop-review-pipeline):** Reviewers own fix
authority. The Applier is a read-only final gate that emits a binary pass/fail
verdict — it never modifies files. Every post-review outcome is terminal:
merge (on `applier:pass` or reviews passed with zero fixes) or `needs-input`
(on `applier:fail`, reviewer `agent-maxed-out`, or post-review deterministic
check failure). There is no fix-iteration loop and no `bugfix` container.

### Model strategy

**Principle:** Use the cheapest model that can do the job. Reserve expensive models for judgment work.

| Work type | Model | Rationale |
|-----------|-------|-----------|
| Build (implementation) | **Haiku** | Clear instructions (tasks.md, design.md). Pattern-following, not judgment. Faster and cheaper — 5 parallel Haiku builds burn far less quota than Sonnet. |
| Fix-quality / fix-browser (pre-review) | **Haiku** | "Fix this PHPCS error" or "fix this browser test failure" — explicit, targeted corrections triggered by deterministic check output during the build phase. |
| Code review (+ in-container fix authority) | **Sonnet → Opus** | Judgment + bounded fixes. Sonnet is the primary; falls back to Opus when Sonnet quota exhausted. Budget: 40 turns (up from 20) to cover review + self-verified fixes. |
| Security review (+ in-container fix authority in PR mode) | **Sonnet → Opus** | Critical: injection vectors, auth bypasses, secret leaks. Same fallback logic. Budget: 40 turns in PR mode, 120 in full-audit mode (audit mode has no fix authority). |
| Applier (Axel Pliér) | **Sonnet → Opus** | Final binary go/no-go. No fix tools. Reads hydra.json + PR state + ADRs, emits `{pass, blocking[]}`. Budget: 20 turns. |
| Audit | **Sonnet → Opus** | Full codebase analysis — needs depth. |

**Quota optimization:** Claude Max plans have separate "Sonnet only" and "all models" weekly limits. By defaulting builders to Haiku, the Sonnet quota is reserved for reviews only (~20 turns each, 2 per PR). When Sonnet runs out, reviews fall back to the **deeper** model (Opus), not the shallower one — because reviews are the last line of defense before human approval.

**Overrides:** Set `HYDRA_BUILDER_MODEL`, `HYDRA_REVIEWER_MODEL`, or `HYDRA_REVIEWER_FALLBACK_MODEL` env vars to change defaults.

### Architecture

```
┌─────────────────────────────────────────────────────┐
│  Scheduler (cron or daemon)                         │
│                                                     │
│  reads: queue table (postgres)                      │
│  writes: container assignments, status updates      │
│                                                     │
│  ┌──────────────────────────────────────────┐       │
│  │ Pool: 10 container slots                 │       │
│  │                                          │       │
│  │  slot-1: [bugfix]     ← highest prio     │       │
│  │  slot-2: [code-review]                   │       │
│  │  slot-3: [build]                         │       │
│  │  slot-4: [build]                         │       │
│  │  slot-5: [classify]                      │       │
│  │  slot-6: [classify]                      │       │
│  │  slot-7: [translate]                     │       │
│  │  slot-8: [discovery]                     │       │
│  │  slot-9: [idle]       ← waiting for work │       │
│  │  slot-10: [idle]                         │       │
│  └──────────────────────────────────────────┘       │
│                                                     │
│  Token rotation: credentials.json (work → private)  │
│  Rate limit: pool-level tracking per account        │
│  Preemption: low-prio containers stopped when       │
│              high-prio work arrives and pool is full │
└─────────────────────────────────────────────────────┘
```

### Queue table (future)

```sql
CREATE TABLE container_queue (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,        -- bugfix, code-review, build, classify, etc.
    priority INTEGER NOT NULL,         -- 1=highest
    payload JSONB NOT NULL,            -- script args, spec slug, issue URL, etc.
    status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
    container_id VARCHAR(100),         -- docker container name when running
    token_account VARCHAR(50),         -- which OAuth account is assigned
    created_at TIMESTAMP DEFAULT NOW(),
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    exit_code INTEGER,
    error_message TEXT
);
```

### Phased rollout

**Phase 1 (now):** All LLM calls containerized. Specter scripts run via `run_llm_containers.sh`. Hydra containers use `run_container_with_fallback`. Both read from `credentials.json`. No shared queue yet — each system schedules its own containers.

**Phase 2:** Shared queue table. A single scheduler script replaces both `cron-hydra.sh` dispatch and `run_llm_containers.sh`. Pool size configurable. Priority enforcement by not starting low-prio work when high-prio is queued.

**Phase 3:** Preemption. Running low-priority containers can be stopped (gracefully, with checkpoint) when high-priority work arrives and all slots are occupied. Container images support checkpoint/resume via DB state.

### Current state (Phase 1)

**Container images:**

| Image | Size | Purpose |
|-------|------|---------|
| `conduction/nextcloud-test:stable31` | 1.5GB | Prebuild NC server + PostgreSQL + OpenRegister (cloned) |
| `hydra-builder:latest` | 1.9GB | Code implementation: NC test env + Claude CLI + PHP + skills |
| `hydra-reviewer:latest` | 1.3GB | Code review + bounded in-container fix authority (Juan Claude van Damme) |
| `hydra-security:latest` | 1.9GB | Security review + bounded in-container fix authority (Clyde Barcode) |
| `hydra-applier:latest` | 1.0GB | Binary go/no-go gate; no Write/Edit tools (Axel Pliér) |
| `specter-spec-writer:latest` | ~800MB | Spec generation: Claude CLI + openspec CLI + skills (no PHP) |
| `specter-llm-worker:latest` | ~500MB | Intelligence pipeline: Claude CLI + DB access |

**Credential separation:**
- **Specter:** `concurrentie-analyse/secrets/credentials.json` (work + private tokens)
- **Hydra:** `hydra/secrets/credentials.json` (work token only)

**Token detection:**
- Container mode: uses exit code (0 = success, non-zero checks output for rate limit)
- Local mode: checks output text for "rate limit" / "auth failed" strings

**NC test environment:**
- Prebuild image with PostgreSQL (matches production, not SQLite)
- Builder `COPY --from=conduction/nextcloud-test` at build time
- Entrypoint starts PG + enables OpenRegister at runtime
- Each container gets its own isolated NC+PG instance

**Spec generation flow:**
- `push_spec_pipeline.py` prepares repos in parallel, generates in `specter-spec-writer` containers
- Each spec gets its own container + clone (compartmentalized)
- Dependency tiers control ordering: Phase 1 → Phase 2 → Phase 3 → Phase 4
- Specs with met deps push to development directly (doc-only merge guard)
- Issues created with `yolo` label → Hydra auto-builds, reviews, merges, closes issue

### Container capability profiles

Each container persona runs with a different Linux capability set determined by the trust we extend to it. This is load-bearing for runtime behaviour — a container's `/workspace` is ONLY writable by the claude user if the build or the entrypoint arranges it, and the two code paths diverge based on cap profile.

| Persona | Caps added | Claude user | Workspace setup |
|---------|-----------|-------------|-----------------|
| Builder | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Dropped via `gosu` at run time | Entrypoint chowns at start, relies on DAC_OVERRIDE |
| Reviewer | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same as builder | Same — entrypoint chown |
| Security | SETUID, SETGID, DAC_OVERRIDE, CHOWN, FOWNER | Same | Same |
| **Applier** | **None** (minimum-cap — read-only judge) | **Runs as `claude:claude` via `docker --user`** (no gosu drop possible — can't setuid without SETUID) | **Must be pre-chowned at IMAGE BUILD TIME** — no runtime chown possible |

**The applier's minimum-cap profile has a hard consequence:** its Dockerfile MUST contain
```dockerfile
RUN mkdir -p /workspace && chown claude:claude /workspace && chmod 0775 /workspace
```
before the `WORKDIR /workspace` directive. Otherwise the non-root claude user cannot write files into its own workdir, `hydra_prefetch_pr_context` silently fails every redirect, Claude runs 0 turns, and the orchestrator records `pass=null, turns=0 → applier:fail`. Observed on decidesk#44 2026-04-23 06:01 UTC — looked like a harness bug, real cause was one missing `chown` line in the Dockerfile.

This is **the rule for any future minimum-cap persona**: if you drop DAC_OVERRIDE + SETUID for security reasons, the Dockerfile owns workspace ownership — the entrypoint cannot.

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized per system (Specter has private fallback, Hydra doesn't)
- Container exit code determines token rotation (not mid-session JSONL text)
- Prebuild NC image eliminates 30-60s clone overhead per builder container
- Container images are the unit of deployment — version, test, rollback independently
- ADR-000 convention: every repo's data model is at `openspec/architecture/adr-000-data-model.md`
- `context-brief.md` in each change directory carries intelligence data through the full pipeline
- Minimum-cap containers (applier) require Dockerfile-time workspace chown; higher-cap containers can chown at runtime. This split is permanent — don't ship a new minimum-cap persona without pre-chowning.

### ADR-014-licensing
- Licence: EUPL-1.2 (European Union Public Licence).
- `appinfo/info.xml`: MUST use `<licence>agpl</licence>` — Nextcloud app store does not recognise EUPL.
- This is intentional dual-tagging, NOT a conflict. Do NOT change info.xml to eupl. Do NOT flag as review finding.

## PHP files — PHPDoc tags only

License and copyright metadata on PHP files lives **only** in the main file docblock as PHPDoc tags:

```php
<?php

/**
 * Short Description
 *
 * Longer description.
 *
 * @category Controller
 * @package  OCA\{AppName}\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/{change-name}/tasks.md#task-N
 */

declare(strict_types=1);
```

**Required tags on every PHP file:** `@author`, `@copyright`, `@license`, `@link`, `@spec`. File-level `@spec` links back to the OpenSpec change that created or last modified the file (ADR-003). Classes and public methods also carry their own `@spec` tag.

**Do NOT add:**
- `SPDX-FileCopyrightText: ...` lines in the docblock — that duplicates `@copyright`.
- `SPDX-License-Identifier: ...` lines in the docblock — that duplicates `@license`.
- `// SPDX-*` line comments before or after the docblock.

## Vue / JS / CSS files

These file types don't carry PHPDoc. Use SPDX header as the first line:

- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
- JS / TS: `// SPDX-License-Identifier: EUPL-1.2`
- CSS / SCSS: `/* SPDX-License-Identifier: EUPL-1.2 */`

## Repo-level REUSE compliance

Every app repo SHOULD carry a `REUSE.toml` at its root declaring license + copyright for every file pattern. This is the authoritative source for REUSE compliance — `reuse lint` reads it instead of requiring per-file SPDX headers for PHP files:

```toml
version = 1

[[annotations]]
path = "**/*.php"
SPDX-FileCopyrightText = "2026 Conduction B.V. <info@conduction.nl>"
SPDX-License-Identifier = "EUPL-1.2"
```

## Hydra quality gate

`scripts/run-quality.sh`'s `spdx-headers` gate enforces: every `lib/**/*.php` file has both `@license` and `@copyright` PHPDoc tags. Missing either fails the gate.

### ADR-015-common-patterns
- Common Conduction patterns. These apply to ALL apps. Every item below was found 3+ times
  across multiple code reviews. Get these right during implementation — not after review.
- When fixing any pattern violation, ALWAYS generalize: grep for the same issue across ALL
  files and fix every instance in one pass. Fixing one file while leaving the same issue in
  nine others guarantees another review round.

### OpenRegister ObjectService API
- `findObject($register, $schema, $id)` — 3 positional args, register first
- `findObjects($register, $schema, $params)` — 3 positional args, $params is filter array
- `saveObject($register, $schema, $object)` — 3 positional args, $object is array
- NEVER `getObject($id)` or `saveObject($data)` — those 1-arg signatures do not exist
- When unsure, check the OpenRegister source or existing app code

### Store registration (Vue/Pinia)
- Register each entity type ONCE in `src/store/store.js` via `createObjectStore`
- NEVER register in both `OBJECT_TYPES` and `ENTITY_STORES` — pick one pattern
- Type names: kebab-case (`action-item`), NOT camelCase (`actionItem`)
- Use platform `createObjectStore` — do NOT build custom stores (hand-rolled object.js)

### Authorization enforcement
- ALL mutation endpoints MUST have `IGroupManager::isAdmin()` check on backend
- Settings endpoints: `#[AuthorizedAdminSetting]` or `@RequireAdmin` annotation
- NEVER rely on frontend-only auth — always enforce on backend
- User identity: derive from `IUserSession` — NEVER trust frontend-sent user IDs
- Null dependency checks: throw 503, do NOT silently return empty response

### Error responses
- NEVER return `$e->getMessage()` to API — use static, generic error messages
- Pattern: `catch (\Throwable $e) { return new JSONResponse(['message' => 'Operation failed'], 500); }`
- Log the real error: `$this->logger->error('Context', ['exception' => $e]);`
- Frontend: EVERY `await store.action()` MUST be in `try/catch` with user feedback

### API calls & CSRF
- Use `axios` from `@nextcloud/axios` for ALL API calls — it auto-attaches the CSRF token
- NEVER use raw `fetch()` for mutations — missing requesttoken causes silent 403 failures
- Pattern: `import axios from '@nextcloud/axios'` + `const { data } = await axios.post(url, payload)`

### Vue component imports
- NEVER import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue` which re-exports everything
- EVERY component used in `<template>` MUST be imported AND listed in `components: {}`
- Vue 2 silently renders unknown elements — a missing import = invisible runtime failure
- Pre-commit check: for every `<NcFoo>` or `<CnFoo>` in template, verify the import exists

### SPDX headers (see also ADR-014)
- EVERY new file needs an SPDX header — apply to ALL new files in one pass
- PHP: `// SPDX-License-Identifier: EUPL-1.2` after `<?php`
- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- JS: `// SPDX-License-Identifier: EUPL-1.2` as first line

### Dependency management
- When importing from a package, verify it exists in `package.json` before committing
- `@nextcloud/auth` for `getRequestToken()` — add to dependencies if missing
- Run `npm ci && npm run lint` to catch `n/no-extraneous-import` BEFORE pushing

### Translations (i18n)
- ALL user-visible strings: `this.t('appid', 'text')` in Vue, `$this->l->t('text')` in PHP
- NEVER hardcode Dutch or English strings in templates, CSV headers, or notifications
- NEVER bare `t()` in Vue — always `this.t()` (Options API)

### Data patterns
- Relations: verify `fetchUsed` vs `fetchUses` direction — wrong direction = empty cards
- Lifecycle: use the service's `transitionLifecycle()` — NEVER `saveObject()` directly for status
- Pagination: `_limit: 999` silently undercounts — use proper pagination or document the cap

### Nextcloud UI patterns
- NEVER `window.confirm()` or `window.alert()` — use `NcDialog` or `CnFormDialog`
- NEVER read app state from DOM (`document.getElementById`, `dataset`) — use backend API
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable)
- Deferred features: if spec says "defer to phase N", do NOT register/enable them in info.xml or anywhere else
- Router: history mode with `generateUrl` base (see ADR-004). Deep link URLs must use path format, NOT hash format.
- Relations: `fetchUsed` = reverse lookup (who references me), `fetchUses` = forward lookup (what do I reference)
- Detail views: every spec-required "linked X section" MUST have a `CnDetailCard` — never stub or omit

### Pre-commit verification (run before EVERY commit)

Before committing, verify your code against these patterns:

1. **SPDX headers**: `grep -rL 'SPDX-License-Identifier' src/ lib/ --include='*.php' --include='*.vue' --include='*.js'`
   → Add headers to EVERY file missing one — all of them, not just one.
2. **ObjectService calls**: `grep -rn 'findObject\|saveObject\|findObjects' lib/ --include='*.php'`
   → Verify every call has 3 positional args: `($register, $schema, $idOrParams)`
3. **Error responses**: `grep -rn 'getMessage()' lib/Controller/ --include='*.php'`
   → Replace any `$e->getMessage()` in JSONResponse with a static error string
4. **Auth checks**: For every POST/PUT/DELETE controller method, verify `IGroupManager::isAdmin()` is called
5. **Store registration**: `grep -rn 'registerObjectType\|OBJECT_TYPES\|ENTITY_STORES' src/`
   → Verify each entity registered exactly once, kebab-case names
6. **Dependencies**: `npm run lint` — catches missing package.json entries
7. **Translations**: `grep -rn "'" src/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` — scan for hardcoded strings
8. **try/catch**: `grep -rn 'await.*Store\.' src/ --include='*.vue'` — verify every store call is wrapped
9. **No raw fetch**: `grep -rn 'fetch(' src/ --include='*.vue' --include='*.js'` — must use `@nextcloud/axios`, not raw fetch (CSRF)
10. **Import source**: `grep -rn "from '@nextcloud/vue'" src/` — must be zero matches. Use `@conduction/nextcloud-vue` instead.
11. **Component imports**: for every `<NcFoo>` or `<CnFoo>` in templates, verify the component is imported AND in `components: {}`
12. **Type slug consistency**: verify every entity type string across ALL files (store, search, routes, views) uses the same kebab-case slug — `grep -rn "agendaItem\|governanceBody\|actionItem" src/` should return zero matches
13. **Translation keys**: `grep -rn "t('.*'," src/ --include='*.vue' --include='*.js'` — verify ALL t() keys are English, not Dutch. Dutch translations go in `l10n/nl.json`.
14. **Route consistency**: verify every entity type referenced in search, navigation, or links has a matching named route in `src/router/`
15. **Task completeness**: re-read tasks.md — every `[x]` task must be fully implemented, not a stub

If ANY check fails, fix ALL instances (not just the first one) before committing.

### ADR-016-routes
- Routes: `appinfo/routes.php` is the ONLY registration path. NO runtime-registered routes, NO route
  fragments in `info.xml`, NO bootstrapped route providers added from `Application::register()`.
- `info.xml` is app metadata only (name, version, dependencies, categories, screenshots). It must
  never carry `<route>` / `<navigation>` entries that map URLs to controllers.
- Every route entry names `controller#method` explicitly — no wildcard auto-discovery, no regex
  generators. Snake_case controller maps to CamelCase class: `meeting#public_state` →
  `MeetingController::publicState()`. Lowering discoverability is the point: grepping `routes.php`
  returns the full URL surface area of the app.
- Admin settings pages: register the settings section via `\OCP\Settings\ISection` in
  `Application::register()`, but the settings URL itself is a standard `appinfo/routes.php` entry
  pointing at a controller method marked with `#[AuthorizedAdminSetting(Application::APP_ID)]`.
- Public (unauthenticated) endpoints: declare `#[PublicPage]` + `#[NoCSRFRequired]` on the method,
  and keep the route in `appinfo/routes.php` — do not invent a separate public-routes file.
- Rationale: the mechanical gates (`hydra-gate-route-auth`) scan `appinfo/routes.php` only. Every
  endpoint living there gets its auth attribute verified; an endpoint registered elsewhere
  bypasses the gate and can ship to production without its middleware posture checked. One file,
  one gate, no drift.
- Migration: any app with routes declared in `info.xml` or injected via `Application::boot()` must
  move them to `appinfo/routes.php` before the next build — the gate treats such endpoints as
  absent, and any related controller method without an auth attribute will surface as a FAIL.

### ADR-017-component-composition
# ADR-017: Component Composition Rules

## Status
Accepted

## Date
2026-04-14

## Context

Conduction apps share a Vue component library (`@conduction/nextcloud-vue`) that provides self-contained, higher-level components like `CnObjectDataWidget`, `CnStatsPanel`, `CnDetailPage`, and `CnTimelineStages`. These components internally render their own card wrappers (`CnDetailCard`), headers, and layout containers.

Developers have been wrapping these self-contained components inside additional layout containers (e.g. `CnDetailCard` wrapping `CnObjectDataWidget`), producing a "card-in-card" visual artifact where headers and borders are doubled. This was found across Procest, Pipelinq, and earlier OpenCatalogi iterations.

The same principle applies to `CnDetailPage` which renders its own `NcAppContent` wrapper — apps must not add another `NcAppContent` around it.

## Decision

### Self-contained components render their own container

The following components are **self-contained** and MUST NOT be wrapped in `CnDetailCard`, `NcAppContent`, or other layout containers:

| Component | Renders its own | Use directly inside |
|---|---|---|
| `CnObjectDataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnObjectMetadataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnStatsPanel` | Sections with headers | `CnDetailPage` slot or `<div>` |
| `CnDetailPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnDashboardPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnIndexPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnTimelineStages` | Standalone timeline | Inside `CnDetailCard` or any container (no own card) |

### How to identify self-contained components

A component is self-contained if its template root is a card, panel, or page-level wrapper. Check the component source: if it starts with `<CnDetailCard>`, `<div class="cn-*-card">`, or similar, it manages its own container.

### Correct patterns

```vue
<!-- CORRECT: CnObjectDataWidget renders its own card -->
<CnObjectDataWidget
  :schema="schema"
  :object-data="data"
  title="Case Information" />

<!-- CORRECT: CnTimelineStages is NOT self-contained, wrap it -->
<CnDetailCard :title="t('app', 'Status')">
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### Anti-patterns

```vue
<!-- WRONG: Double card wrapping -->
<CnDetailCard :title="t('app', 'Case Information')">
  <CnObjectDataWidget :schema="schema" :object-data="data" />
</CnDetailCard>

<!-- WRONG: Double page wrapping -->
<NcAppContent>
  <CnDetailPage :title="title">...</CnDetailPage>
</NcAppContent>
```

### External sidebar pattern

Components like `CnDetailPage` that support sidebars communicate with a parent-provided `objectSidebarState` via Vue's `provide`/`inject`. The sidebar component (`CnObjectSidebar`) MUST be rendered at the `NcContent` level in `App.vue`, NOT inside `NcAppContent`:

```vue
<!-- App.vue -->
<NcContent app-name="myapp">
  <MainMenu />
  <NcAppContent>
    <router-view />
  </NcAppContent>
  <CnObjectSidebar v-if="objectSidebarState.active" ... />
</NcContent>
```

## Consequences

- Developers must check if a shared component is self-contained before wrapping it
- The component library documents which components are self-contained in their JSDoc headers
- Code reviews should flag card-in-card nesting as a pattern violation
- Existing violations should be fixed when encountered (per ADR-015 pre-existing issues rule)

### ADR-018-widget-header-actions
# ADR-018: Widget Header Actions Pattern

## Status
Accepted

## Date
2026-04-14

## Context

Card and widget components across Conduction apps need action controls (buttons, dropdowns, selects) for user interactions like changing status, adding items, or toggling views. Developers have been placing these controls inline with card content, taking up vertical space and creating inconsistent layouts.

Nextcloud's own UI pattern places actions in the title bar (top-right) of panels and sidebars. Our shared component library should enforce this same pattern so all card/widget components have a consistent location for actions.

## Decision

### All card/widget components MUST support a `header-actions` slot

Every component that renders a title bar or header MUST provide a `header-actions` slot positioned in the **top-right of the header**, inline with the title. This is the standard location for action controls.

### Standard slot name: `header-actions`

All components use the slot name `header-actions` for consistency. Components that previously used `actions` retain it for backwards compatibility but `header-actions` is the canonical name.

### Component support status

All card/widget components in `@conduction/nextcloud-vue` now support `header-actions`:

| Component | Slot name | Notes |
|---|---|---|
| `CnDetailCard` | `header-actions` | Primary card component |
| `CnWidgetWrapper` | `header-actions` | Dashboard widget container |
| `CnObjectDataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnObjectMetadataWidget` | `header-actions` | Passes through to CnDetailCard |
| `CnStatsPanel` | `header-actions` | Added in this ADR |
| `CnSettingsCard` | `header-actions` | Added in this ADR |
| `CnConfigurationCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |
| `CnVersionInfoCard` | `header-actions` + `actions` (legacy) | `header-actions` added alongside existing `actions` |

### What goes in header-actions

- Status change dropdowns / selects
- Add/create buttons
- Toggle switches (e.g. edit mode)
- Refresh buttons
- Filter controls specific to this widget

### What does NOT go in header-actions

- Save/cancel for the entire page (those belong in `CnDetailPage` `#header-actions`)
- Bulk action toolbars (those belong in `CnMassActionBar`)
- Form inputs that are part of the data being edited

### Usage pattern

```vue
<CnDetailCard :title="t('app', 'Status')">
  <template #header-actions>
    <NcSelect
      v-model="selectedStatus"
      :options="statusOptions"
      :placeholder="t('app', 'Change status...')" />
  </template>

  <!-- Card content -->
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### New components

When creating new card or widget components, the `header-actions` slot MUST be included from the start. The standard template pattern:

```vue
<div class="cn-my-widget__header">
  <h4 class="cn-my-widget__title">{{ title }}</h4>
  <div v-if="$slots['header-actions']" class="cn-my-widget__header-actions">
    <slot name="header-actions" />
  </div>
</div>
```

With CSS:
```css
.cn-my-widget__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cn-my-widget__header-actions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}
```

## Consequences

- All existing card components now support `header-actions`
- New components must include this slot from creation
- Existing apps should migrate inline actions to `header-actions` when touching those files
- Code reviews should flag action controls placed in card content as a pattern violation
- The `actions` slot name in CnConfigurationCard and CnVersionInfoCard is deprecated but retained for backwards compatibility

### ADR-019-integration-registry
# ADR-019: Integration Registry Pattern

## Status
Proposed

## Date
2026-04-21

## Context

Conduction apps (OpenCatalogi, Procest, Pipelinq, LaunchPad, Decidesk, DocuDesk, ZaakAfhandelApp, Larpingapp, Softwarecatalog, OpenRegister itself) all consume the same set of "things linked to an object" — files, notes, tasks, calendar events, mail, contacts, deck cards, talk conversations, and an expanding catalogue of NC-ecosystem and external services.

Until now this was implemented in two rigid places:

- `OCA\OpenRegister\Service\LinkedEntityService::TYPE_COLUMN_MAP` — a hardcoded PHP constant naming the 8 supported NC entity types.
- `@conduction/nextcloud-vue::CnObjectSidebar` — a Vue component with 5 hardcoded tabs and inline imports for each.

Adding a new integration required modifying both core OR and the shared component library. External services (OpenProject, XWiki, ...) had no path at all. Of the 8 backend-supported types, only 5 had sidebar UI and only 2 had widget components — a glaring asymmetry that grew worse with every new backend integration that landed without UI.

## Decision

Adopt a **two-sided integration registry** pattern as the canonical mechanism for declaring "things that can be linked to or rendered alongside an OpenRegister object."

### The contract — one provider, three artifacts

Every integration ships a vertical slice declared via:

1. A PHP class implementing `OCA\OpenRegister\Service\Integration\IntegrationProvider` (registered via DI tag `IntegrationProvider`).
2. A frontend registration call `OCA.OpenRegister.integrations.register({ id, label, icon, tab, widget, ... })`.

The two registrations share the same `id` — backend and frontend are paired by id, not by import.

### Three-stage filter

What the user actually sees is decided by three independent filters, each with distinct ownership:

| Stage | Owner | Question |
|---|---|---|
| **Registry** | Provider author (system) | Does this integration exist + is the required NC app installed? |
| **Schema** | Schema author (data designer) | Is this integration relevant to objects of this schema? |
| **Component** | Page author (app developer) | Should this integration appear on THIS surface? |

Stage 1 is `IntegrationRegistry::getEnabled()`. Stage 2 is the schema's `configuration.linkedTypes` whitelist. Stage 3 is the rendering component's `excludeIntegrations` prop (or equivalent layout choice).

Each stage has clear ownership; debugging "why isn't X showing?" walks the three stages in order.

### Widget parity is a hard rule

Registering an integration without **both** a sidebar tab component **and** a card widget component is a CI-enforced failure. The check runs in pre-commit, repository CI, and the hydra quality gate. Tab-only or widget-only integrations are not permitted.

### Four widget surfaces with graceful fallback

Widgets render across four surfaces: `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`. A registered widget receives the `surface` as a prop and may branch internally. Optional surface-specific components (`widgetCompact`, `widgetExpanded`, `widgetEntity`) are used when present. A new surface added in the future falls back to the main `widget` — no re-registration required from existing integrations.

### External integrations route through OpenConnector

Providers may declare `getStorageStrategy() === 'external'` and reference an OpenConnector source. OR's `ExternalIntegrationRouter` handles dispatch + auth-status surfacing. OR does not own credentials — OpenConnector does. The provider declares its `authRequirements()` so OR can show a unified admin UI and surface auth status via OCS capabilities.

### Schema validator is registry-driven

`Schema::validateLinkedTypesValue()` consults `IntegrationRegistry::listIds()` rather than a hardcoded constant. New integrations are immediately valid as `linkedTypes` values without core changes.

### Reference-property auto-rendering

A new schema property marker `referenceType: <integration-id>` causes `CnFormDialog` and `CnDetailGrid` to render the matching integration's `single-entity` widget inline next to the property. The integration registry is the single source of truth for "how to render a linked thing of this type" everywhere it appears, not just in sidebars and dashboards.

## Consequences

### Positive

- **Extensibility**: any Conduction app, third-party integrator, or external-service connector can add an integration without modifying OR core or `@conduction/nextcloud-vue`.
- **Consistency**: every integration is rendered the same way, with the same lifecycle, the same RBAC hooks, the same auth surface, the same parity contract.
- **Discoverability**: integrations are advertised via OCS capabilities — mobile apps, partner integrations, and other NC apps can discover what's available without proprietary endpoints.
- **Parallelism**: leaf changes (one per integration) hang off this contract and run in parallel through hydra's pool. The current backend-vs-UI asymmetry cannot recur — parity is enforced.
- **Future flexibility**: the contract is "linked thing"–shaped so `RelationsService` (object↔object) can be unified under the same registry in a future change without breaking changes.

### Negative

- **Onboarding ceremony**: adding a new integration means more files than before (provider, tab, widget, registration, spec delta, tests). Mitigated by `scripts/scaffold-integration.sh <id>` which generates the skeleton.
- **Bundle discipline**: an integration that fails to register (wrong load order, missed `register()` call) silently vanishes. Mitigated by the parity CI gate catching missing declarations pre-merge and a dev-mode warning when a backend provider has no frontend counterpart.
- **One more abstraction**: developers reading sidebar/dashboard code must understand "why isn't this just a static import?" Mitigated by the developer guide and this ADR.

### Migration risks

- **Schema `linkedTypes` referencing not-yet-registered ids**: handled — validation is permissive on read (warns but doesn't reject), strict on write only when adding.
- **External consumers of `LinkedEntityService::TYPE_COLUMN_MAP`**: the constant is private-by-convention and not documented as public API; we don't expect external consumers. It is `@deprecated` here and removed in a follow-up cleanup change once built-in providers stabilise.
- **`CnObjectSidebar` props/slots**: every existing prop and slot is preserved. Snapshot tests guard against regressions on the 5 existing tabs.

## Companion ADR

This ADR codifies the **mechanism**. A separate companion ADR — **ADR-020: Apps Consume OpenRegister Abstractions** — codifies the broader **principle**: Conduction apps hook into OpenRegister's abstractions (registers, schemas, objects, integrations, RBAC, audit, archival, ...) rather than building parallel mechanisms. ADR-020 is authored separately; ADR-019 is the first concrete instance of that principle being applied systematically.

## Implementation reference

- Umbrella change: `openregister/openspec/changes/pluggable-integration-registry/` (proposal, design, tasks, spec, hydra.json)
- Implementation files: `openregister/lib/Service/Integration/`, `nextcloud-vue/src/integrations/`
- Developer guide: `openregister/docs/integrations/README.md`
- Scaffold script: `openregister/scripts/scaffold-integration.sh`
- Parity check: `openregister/scripts/check-integration-parity.sh`

## References

- ADR-004 — Frontend (Vue 2, axios, components)
- ADR-007 — i18n (nl + en required)
- ADR-010 — NL Design System
- ADR-011 — Schema standards
- ADR-017 — Component composition
- ADR-018 — Widget header actions
- ADR-020 — Apps consume OR abstractions (companion, separate change)

## Ownership

OpenRegister team owns the registry contract, the built-in providers, and the schema validator changes. `@conduction/nextcloud-vue` maintainers own the frontend registry, surface contracts, and the three new widgets. Each integration leaf change has its own owner.

### ADR-020-gate-scope-to-pr-diff
# ADR-020 — Mechanical gates are scoped to the PR diff, not the whole repo

## Context

Hydra's 8 mechanical gates (`scripts/run-hydra-gates.sh`) were authored as repo-wide scanners: every `lib/**.php` file was checked on every pipeline run. This made pre-existing debt in unchanged files block every new PR. Concretely, decidesk#44 / #45 bounced through `code-review:fail → security-review:fail → needs-input` multiple cycles because `lib/Controller/SettingsController.php` (not touched by either PR) had two genuine findings — missing `#[AuthorizedAdminSetting]` on `load()` and missing `STATUS_UNAUTHORIZED` guard on `index()`. The reviewer cannot fix unchanged files in bounded scope, the builder will not re-enter fix mode for someone else's debt, and the applier refuses to override reviewer-fail verdicts. Result: two genuinely-clean PRs stuck in a ping-pong for days.

The reviewer's CLAUDE.md has long instructed Claude to apply the diff scope manually, but that is (a) advisory, not enforced, and (b) wastes turns on every run.

## Decision

Every mechanical gate in `scripts/run-hydra-gates.sh` must honor the `--scope-to-diff [BASE_REF]` flag. When set, the gate iterates only over files added, copied, modified, or renamed (`--diff-filter=ACMR`) between `BASE_REF` (default `origin/development`) and `HEAD`. Inherited debt in unchanged files is documented by a full-repo cleanup PR, not enforced via review blockers on unrelated work.

All four pipeline positions that invoke gates use `--scope-to-diff`:

| Position | Invocation site | Why scope-to-diff |
|---|---|---|
| Builder Rule 0b wrapper | `images/builder/entrypoint.sh` | Builder is creating the PR; the diff is its output. |
| Code reviewer pre-flight | `images/reviewer/entrypoint.sh` | Juan reviews the PR, not the base branch. |
| Code reviewer post-flight | `images/reviewer/entrypoint.sh` | Post-flight gate fails when Juan introduces debt; inherited debt is out of scope. |
| Security reviewer pre-flight | `images/security/entrypoint.sh` | Same rationale as code review. |
| Security reviewer post-flight | `images/security/entrypoint.sh` | Same. |

The applier runs no gates directly — it consumes the reviewers' verdicts, which now reflect scope-correct findings.

Base ref is overridable via the `HYDRA_GATE_BASE_REF` env var (default `origin/development`) for repos with a different mainline.

Gate 4 (`composer-audit`) is skipped entirely when scope-to-diff is active and neither `composer.json` nor `composer.lock` is in the diff — dep vulnerabilities are unchanged if deps are unchanged. Gate 6 (`orphan-auth`) scopes the *defining* file by diff but keeps its caller grep repo-wide so a method newly-added in the PR is still validated against any legitimate same-file or cross-file caller.

## Consequences

**Positive**
- Existing debt in unchanged files no longer blocks PRs on unrelated features. The decidesk#44/#45 ping-pong is structurally impossible going forward.
- Builder, reviewer, and security all see the same scoped gate output — no more cycle-of-life where each position reads different baselines.
- Faster pipeline runs: scanning ~20 changed files instead of ~200+ repo files per gate.

**Negative**
- Inherited debt is genuinely invisible to the pipeline until it lands in a PR. Mitigation: a full-repo audit (scope-to-diff off) runs on the `ready-for-audit` label via `cron-audit.sh`, keeping the base-branch state observable.
- A PR that ONLY modifies a file lightly (e.g. renames it) may have gates pass on that file even if it has pre-existing debt. Acceptable — gates judge what the PR touched, not the file's full history.

**Deferred to Phase G.1**
- `composer check:strict` (phpcs, phpmd, psalm, phpstan) and `phpunit` / `npm run lint` are still full-repo. They run inside `composer`/`phpunit` which don't accept per-file scoping cleanly without per-tool argument passthrough. The same scoping story will land there next; for now, the reviewer's manual scope filter (`/tmp/pr-scope.txt`) remains the safety net.

## Verification

Smoke-test on decidesk PR #131 (feature/47/p2-motion-and-voting-core-t2) 2026-04-23:
- Full-repo scan: 2 FAIL (SettingsController in unchanged file)
- `--scope-to-diff --base origin/development`: ALL 8 GATES GREEN

The PR is now unblockable by unrelated debt without sacrificing gate coverage on the 19 files it actually changed.

### ADR-021-bounded-fix-scope-by-shape
# ADR-021: Reviewer bounded-fix scope is defined by change shape, not line count

**Status:** accepted
**Date:** 2026-04-23

## Context

The reviewer containers (Juan Claude van Damme for code, Clyde Barcode for security) run with bounded fix authority — they MAY apply small remediations in-container, commit, and push. The original rule in their CLAUDE.md:

> The fix is bounded to **1–3 lines in one file**.

This rule was an attempt to keep reviewers out of architectural territory. In practice it failed in two directions:

**1. Wrong-shaped for common security patterns.** A typical missing-authorization fix — add a `checkUserRole($uid, ['chair','secretary'])` block with try/catch — is 5–10 physical lines. Reviewers correctly declined to fix under the 3-line rule. On decidesk#45 (PR#129), Clyde flagged the same two auth stubs across **eight review cycles** from 2026-04-21 to 2026-04-23, each time declining as "exceeds 3-line bounded fix scope" or "architectural decision needed". The fix was literally mirroring a sibling method (`transitionLifecycle`) in the same class — zero new concepts, just apply the existing pattern. The 3-line limit turned a mechanical fix into architectural churn.

**2. Ambiguous under formatter changes.** Does "line" mean physical lines? Logical statements? With braces? A single prettier or phpcs run can convert a 3-line compact form into a 7-line expanded form and flip fix authority on or off. Reviewers should not be measuring code in a unit that formatters can redefine.

Meanwhile, genuine architectural work — new services, new schemas, new DI — IS well understood across the team. The category error was confusing "how much code changes" with "how much thinking changes".

A 10-line change that mirrors a sibling method is safer than a 2-line change that invents a new concept. We should scope by what the change touches, not by its size.

## Decision

Reviewer bounded-fix scope is defined by **change shape**, not line count. A fix is in-scope when ALL of these hold:

1. **The shape is one of:**
   - Modify an existing method body (guard clause, try/catch, validation, escape, swap unsafe call for safe one)
   - Add a new **private** helper method in the same class (no public API change)
   - Apply a pattern that **already exists in the same file or class** — mirror a sibling method
   - Add a missing attribute / annotation / docblock tag
   - Swap an unsafe API for its safe counterpart (`md5` → `password_hash`, raw SQL → prepared statement, raw HTML → `htmlspecialchars`)

2. **The change does NOT:**
   - Add a new constructor parameter or new dependency injection
   - Add a new service, class, interface, or route
   - Touch database schema or migrations
   - Change any public method signature visible to callers outside the class
   - Rewrite the file's top-level control flow

3. **Self-verify stays green.** Semgrep (security) or phpcs + covering phpunit (code) on the touched file produces 0 new findings.

The "sibling precedent" clause is explicit: **if a method in the same class demonstrates the fix, the "architectural decision needed" escape hatch does NOT apply.** This is the clause that closes the #45 trap — the precedent in `transitionLifecycle` makes mirroring it mechanical, regardless of how many lines the mirror takes.

## Consequences

**Positive**
- Auth-guard mirroring is now in-scope for reviewers — the most common security-fix pattern stops escalating.
- Scope is robust under formatter changes: `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on one line or three lines is the same fix.
- The "architectural" label is reserved for genuine architectural work (new services, new roles, new DI) where a human really does need to decide something.
- Fewer `needs-input` escalations on recurring findings — fewer retry cycles — less pipeline capacity burned per PR.

**Negative**
- Reviewers have slightly more scope and therefore slightly more room to make wrong calls. Mitigations:
  - The self-verify gate (Semgrep / phpcs + phpunit green on the touched file) is unchanged — still a hard stop on regressions.
  - "No new DI / schema / public signature" is a bright line that protects the expensive classes of change.
  - "Pattern exists in same file/class" is conservative — it prevents invention, only permits mirroring.
- Reviewers now need to read adjacent methods in the same class to check for precedent. This is a small turn-count cost but produces strictly better fixes.

**Neutral**
- Line-count as a heuristic is abandoned. Reviewers still prefer small fixes over large ones — the shape rules make that natural without encoding a brittle number.

## Implementation

Applied to:
- `images/reviewer/CLAUDE.md` — the "Bound-fixable" row in the fix-category table + the "Warnings ARE in scope for fix" section
- `images/security/CLAUDE.md` — the "What you MAY fix in-container" and "What you MUST NOT fix" sections

Rolled out via PR [#136](https://github.com/ConductionNL/hydra/pull/136), 2026-04-23.

## References

- Observed failure: decidesk#45 security-review, 8 cycles documented in [docs/retrospectives/decidesk-44-45-phase-g.md](../../docs/retrospectives/decidesk-44-45-phase-g.md)
- ADR-013 (container pool) defines the reviewer personas; this ADR defines their authority surface.
- ADR-020 (gate scope-to-diff) is the adjacent Phase G work — together these two ADRs remove the two biggest classes of false-escalation observed on the pipeline.

## App-Specific ADRs (1)

These ADRs are specific to Procest.

### 000-data-model: ADR-000: Data Model — procest
# Data Model — Procest

**App:** Procest — Case management, VTH, forms
**Platform:** OpenRegister (register/schema/object pattern)
**Entities:** 39

OpenRegister built-in fields available on ALL entities (do NOT redefine):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

OpenRegister built-in capabilities (do NOT rebuild):
CRUD REST API, CSV/JSON/XML import+export, full-text search, filtering,
pagination, audit trails, file attachments, relation management, locking.

---

## abonnement
**Schema.org type:** `schema:SubscribeAction`
**Purpose:** A subscription (abonnement) for receiving ZGW notifications
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callbackUrl | string | Yes | URL to POST notifications to |
| auth | string | Yes | Authorization header value for callback requests |
| kanalen | string | Yes | Channels and filters to subscribe to (JSON-encoded array) |

---

## adviesAanvraag
**Schema.org type:** `schema:AskAction`
**Purpose:** A request for internal or external advice on a case, with deadline tracking
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case this advice is requested for |
| adviseur | string | Yes | User UID (internal) or organization name (external) |
| type | string | Yes | Whether advice is from internal staff or external party |
| onderwerp | string | No | Subject/topic of the advice request |
| deadline | string | No | Deadline for receiving the advice |
| status | string | No | Current status of the advice request |
| adviesDocument | string | No | Nextcloud file ID of the advice document |
| requestedAt | string | No | Timestamp when the advice was requested |
| receivedAt | string | No | Timestamp when the advice was received |
| questions | string | No | Specific questions for the adviseur |

---

## advisoryReport
**Schema.org type:** `schema:Report`
**Purpose:** Advisory committee report (advies bezwaarschriftencommissie) — records the committee's advice on a bezwaar case per Awb art. 7:13
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this report belongs to |
| hearingSession | string | No | The hearing session this report is based on |
| committeeChair | string | Yes | Voorzitter who signed the report |
| committeeMembers | string | No | JSON-encoded array of committee member role UUIDs |
| adviceDate | string | Yes | Date the advice was issued |
| adviceType | string | Yes | Type of advice: upheld, rejected, partially upheld, inadmissible |
| summary | string | Yes | Summary of the committee's advice |
| grounds | string | Yes | Legal reasoning and grounds for the advice |
| recommendation | string | Yes | Recommended action for the bestuursorgaan |
| deviationFromPrimaryDecision | boolean | Yes | Whether the committee advises differently from the original decision |
| reportDocument | string | No | Reference to full advisory report document |

**Relations:**
- → case (many-to-one)
- → hearingSession (many-to-one)
- → role (many-to-one)

---

## appealDecision
**Schema.org type:** `schema:LegalForceStatus`
**Purpose:** Beslissing op bezwaar (decision on objection) — formal decision recording with disposition, motivation, and rechtsmiddelenclausule per Awb art. 7:11-7:12
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case |
| contestedDecision | string | Yes | The original besluit being contested |
| advisoryReport | string | No | The committee's advisory report |
| dispositionType | string | Yes | Decision outcome type |
| dispositionDetails | string | Yes | Detailed motivation for the decision (motiveringsplicht art. 7:12) |
| followsAdvice | boolean | No | Whether the decision follows the committee's advice |
| deviationReason | string | No | Reason for deviating from committee advice (required when followsAdvice is false) |
| remedialAction | string | No | Corrective action taken if gegrond/deels_gegrond |
| replacementDecision | string | No | New besluit that replaces the contested one |
| decisionDate | string | Yes | Date the decision was made |
| effectiveDate | string | Yes | Date the decision takes legal effect |
| appealInformation | string | Yes | Information about beroep possibilities (rechtsmiddelenclausule) |
| decisionMaker | string | Yes | The person/body that made the decision |
| decisionDocument | string | No | Reference to the formal decision letter document |

**Relations:**
- → case (many-to-one)
- → decision (many-to-one)
- → advisoryReport (many-to-one)
- → decision (many-to-one)
- → role (many-to-one)

---

## case
**Schema.org type:** `schema:Project`
**Purpose:** A case instance in the case management system
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of this case |
| description | string | No | Detailed description of this case |
| identifier | string | No | Auto-generated case identifier (e.g. 2026-0042) |
| caseType | string | Yes | Reference to the case type |
| status | string | No | Reference to the current status type |
| result | string | No | Reference to the result record (set on completion) |
| startDate | string | No | Date the case was started |
| endDate | string | No | Date the case was completed |
| plannedEndDate | string | No | Planned end date |
| deadline | string | No | Processing deadline |
| confidentiality | string | No | Confidentiality level |
| assignee | string | No | Nextcloud user ID of the primary handler |
| priority | string | No | Case priority |
| parentCase | string | No | Reference to parent case (for sub-cases) |
| relatedCases | string | No | References to related cases (JSON-encoded array) |
| geometry | string | No | GeoJSON geometry for location-based cases (JSON-encoded object) |
| statusHistory | string | No | History of status changes (JSON-encoded array) |
| activity | string | No | Activity log entries (JSON-encoded array) |
| extensionCount | integer | No | Number of deadline extensions applied |
| sourceOrganisation | string | No | RSIN of the organization that created this case |
| archiveNomination | string | No | Whether the case should be permanently archived or destroyed |
| archiveActionDate | string | No | Date when the archive action should be executed |
| archiveStatus | string | No | Current archive status of the case |
| paymentIndication | string | No | Payment status indicator |
| lastPaymentDate | string | No | Date of the last payment |
| communicationChannel | string | No | URL reference to the communication channel |
| workflowTemplate | string | No | Reference to the bound workflow template |
| workflowVersion | integer | No | Version number of the bound workflow template |

---

## caseDocument
**Purpose:** Links a document to a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| document | string | Yes | URI reference to the document |
| title | string | No | Title/description of the relation |
| description | string | No | Description of the relation |
| registrationDate | string | No | Registration date |

**Relations:**
- → case (many-to-one)

---

## caseObject
**Purpose:** Links an external object to a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| objectUrl | string | No | URL of the external object |
| objectType | string | Yes | Type of the external object |
| objectIdentification | string | No | JSON identification of the object |
| description | string | No | Description of the relation |

**Relations:**
- → case (many-to-one)

---

## caseProperty
**Purpose:** A property value on a specific case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| propertyDefinition | string | Yes | Reference to the property definition (eigenschap) |
| value | string | Yes | The property value |

**Relations:**
- → case (many-to-one)

---

## caseType
**Schema.org type:** `schema:Project`
**Purpose:** Case type definition — defines the blueprint for a category of cases including lifecycle, deadlines, and classification
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Name of this case type |
| description | string | No | Detailed description of this case type |
| identifier | string | No | Auto-generated identifier |
| catalogus | string | No | Reference to the parent catalogus |
| purpose | string | No | The purpose or goal of this case type |
| trigger | string | No | What triggers the creation of a case of this type |
| subject | string | No | The subject matter of this case type |
| processingDeadline | string | No | ISO 8601 duration for the processing deadline (e.g. P30D) |
| confidentiality | string | No | Confidentiality level |
| isDraft | boolean | No | Whether this case type is a draft (not yet published) |
| validFrom | string | No | Date from which this case type is valid |
| validUntil | string | No | Date until which this case type is valid (null = indefinite) |
| origin | string | No | Initiator action (e.g. indienen, aanvragen) |
| suspensionAllowed | boolean | No | Whether cases of this type can be suspended |
| extensionAllowed | boolean | No | Whether the processing deadline can be extended |
| extensionPeriod | string | No | ISO 8601 duration for extension period (e.g. P14D) |
| publicationRequired | boolean | No | Whether publication of the decision is required |
| internalOrExternal | string | No | Whether the case type is internal or external |
| handlerAction | string | No | Action performed by the handler |
| productsOrServices | string | No | URLs to products or services (JSON-encoded array) |
| selectionListProcessType | string | No | URL to the selection list process type |
| referenceProcess | string | No | Reference process definition (JSON-encoded object) |
| responsible | string | No | Responsible person or department |
| relatedCaseTypes | string | No | Related case types (JSON-encoded array) |
| subCaseTypes | array | No | References to sub-case types (deelzaaktypen) |
| decisionTypes | array | No | References to decision types (besluittypen) linked to this case type |

---

## catalogus
**Schema.org type:** `schema:DataCatalog`
**Purpose:** A catalogus groups case types, decision types, and document types
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| domein | string | Yes | Abbreviated domain name (max 5 characters) |
| rsin | string | No | RSIN of the responsible organisation |
| contactpersoonBeheerNaam | string | No | Name of the management contact |
| contactpersoonBeheerTelefoonnummer | string | No | Phone number of the management contact |
| contactpersoonBeheerEmailadres | string | No | Email of the management contact |

---

## customerContact
**Purpose:** A customer contact moment for a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| contactDateTime | string | No | Date-time of the contact |
| channel | string | No | Communication channel |
| subject | string | No | Subject of the contact |
| initiator | string | No | Who initiated the contact |

**Relations:**
- → case (many-to-one)

---

## decision
**Schema.org type:** `schema:ChooseAction`
**Purpose:** A formal decision on a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | No | Title of this decision |
| case | string | No | Reference to the case |
| description | string | No | Description of this decision |
| decisionType | string | No | Reference to the decision type |
| responsibleOrganisation | string | No | RSIN of the responsible organisation |
| decisionDate | string | No | Date the decision was made |
| effectiveDate | string | No | Date the decision takes effect |
| expiryDate | string | No | Date the decision expires |
| publicationDate | string | No | Publication date |
| deliveryDate | string | No | Delivery date |
| explanation | string | No | Explanation of the decision |
| governingBody | string | No | The governing body that made the decision (bestuursorgaan) |

**Relations:**
- → case (many-to-one)

---

## decisionDocument
**Purpose:** Links a document to a decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decision | string | Yes | Reference to the decision |
| document | string | Yes | URI reference to the document |

**Relations:**
- → decision (many-to-one)

---

## decisionType
**Schema.org type:** `schema:ChooseAction`
**Purpose:** Decision type definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this decision type |
| description | string | No | Description of this decision type |
| catalogus | string | No | Reference to the parent catalogus |
| caseType | string | No | Reference to the parent case type |
| isDraft | boolean | No | Whether this decision type is a draft (concept) |
| publicationRequired | boolean | No | Whether this decision type requires publication |
| caseTypes | array | No | References to case types (array of zaaktype URLs) |
| documentTypes | array | No | References to document types (array of informatieobjecttype URLs) |
| validFrom | string | No | Date from which this decision type is valid |
| validUntil | string | No | Date until which this decision type is valid |

---

## dispatch
**Purpose:** A document dispatch record
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document |
| involvedParty | string | No | URI of the involved party |
| relationshipType | string | Yes | Type of relationship (afzender/geadresseerde) |
| description | string | No | Description of the dispatch |
| receiveDate | string | No | Date received |
| sendDate | string | No | Date sent |
| contactPerson | string | No | Contact person URI |
| contactPersonName | string | No | Name of the contact person |

---

## document
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** A document (enkelvoudig informatieobject) in the document registry
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| identifier | string | No | Auto-generated document identifier |
| sourceOrganisation | string | No | RSIN of the source organisation |
| creationDate | string | No | Date the document was created |
| title | string | Yes | Title of this document |
| confidentiality | string | No | Confidentiality level |
| author | string | No | Author of the document |
| status | string | No | Document status |
| format | string | No | MIME type of the document (e.g. application/pdf) |
| language | string | No | Language of the document (ISO 639-2/B) |
| fileName | string | No | Original file name |
| fileSize | integer | No | File size in bytes |
| content | string | No | Base64-encoded file content or file reference |
| link | string | No | URL to the document |
| description | string | No | Description of the document |
| documentType | string | No | Reference to the document type |
| locked | boolean | No | Whether the document is locked for editing |
| lockId | string | No | Identifier of the current lock |
| fileParts | string | No | References to file parts for chunked uploads (JSON-encoded array) |
| usageRightsIndication | boolean | No | Indicates whether usage rights have been set for this document |

---

## documentLink
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** A link between a document and a case or decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| object | string | Yes | URI reference to the related object (zaak or besluit) |
| objectType | string | Yes | Type of the related object |

---

## documentType
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Document type requirement for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this document type (e.g. Situatietekening) |
| description | string | No | Description of this document type |
| catalogus | string | No | Reference to the parent catalogus |
| caseType | string | No | Reference to the parent case type |
| isDraft | boolean | No | Whether this document type is a draft (concept) |
| confidentiality | string | No | Confidentiality level |
| category | string | No | Document type category |
| isRequired | boolean | No | Whether this document is required for the case |
| allowedMimeTypes | string | No | Allowed MIME types (JSON-encoded array) |
| validFrom | string | No | Date from which this document type is valid |
| validUntil | string | No | Date until which this document type is valid |

---

## handhavingsactie
**Schema.org type:** `schema:LegalForceStatus`
**Purpose:** An enforcement action (handhavingsactie) on a case, classified per the Landelijke Handhavingsstrategie (LHS)
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the handhavingszaak |
| type | string | Yes | Type of enforcement action |
| ernst | string | Yes | Severity of the violation (LHS ernst axis) |
| gedrag | string | Yes | Behavior of the violator (LHS gedrag axis) |
| interventie | string | No | Suggested intervention from LHS matrix (may be overridden) |
| begunstigingstermijn | integer | No | Grace period in days before enforcement takes effect |
| dwangsomBedrag | number | No | Penalty amount per violation (EUR) |
| dwangsomMaximaal | number | No | Maximum total penalty amount (EUR) |
| effectueringsDatum | string | No | Date when enforcement action takes effect |
| status | string | No | Current status of the enforcement action |
| overrideReason | string | No | Documented reasoning if the LHS suggestion was overridden |

---

## hearingSession
**Schema.org type:** `schema:Event`
**Purpose:** Hoorzitting (hearing) — manages scheduling, invitations, and minutes for bezwaar hearings per Awb art. 7:2
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this hearing belongs to |
| scheduledDate | string | Yes | Date and time of the hearing |
| location | string | No | Physical location or 'Online' for video hearings |
| videoCallUrl | string | No | Video conference link for online hearings |
| chairperson | string | Yes | Who chairs the hearing (voorzitter) |
| members | string | No | JSON-encoded array of committee member role UUIDs |
| invitees | string | Yes | JSON-encoded array of invitee objects (name, role, email, status) |
| minutesSummary | string | No | Summary of what was discussed (verslag) |
| minutesDocument | string | No | Reference to full hearing minutes document |
| status | string | Yes | Hearing session status |
| hearingWaived | boolean | No | Bezwaarmaker has waived the right to be heard |
| waiverReason | string | No | Reason for waiving hearing right |

**Relations:**
- → case (many-to-one)
- → role (many-to-one)

---

## inspectieChecklist
**Schema.org type:** `schema:HowTo`
**Purpose:** Configurable inspection checklist template linked to a case type, with versioning support
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this checklist (e.g. 'Bouwtoezicht fase 1 - Fundering') |
| caseType | string | Yes | Reference to the case type this checklist belongs to |
| version | integer | No | Version number of this checklist (incremented on edit) |
| status | string | No | Lifecycle status of this checklist version |
| items | array | No | Ordered list of checklist items |

---

## inspectieRapport
**Schema.org type:** `schema:Report`
**Purpose:** A completed inspection report generated from a checklist, stored on the case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case (toezichtzaak) this report belongs to |
| checklist | string | Yes | Reference to the inspectieChecklist used |
| inspector | string | Yes | User UID of the inspector |
| inspectionDate | string | Yes | Date and time of the inspection |
| location | string | No | GPS coordinates or address of the inspection location |
| result | string | No | Overall inspection result (auto-calculated from items) |
| failedItems | integer | No | Count of failed checklist items |
| items | array | No | Completed checklist item results |
| photos | array | No | All Nextcloud file IDs of photos taken during inspection |
| remarks | string | No | General remarks about the inspection |
| followUpRequired | boolean | No | Whether follow-up action is required |

---

## kanaal
**Schema.org type:** `schema:BroadcastChannel`
**Purpose:** A notification channel (kanaal) for ZGW event distribution
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string | Yes | Name of this channel (e.g. zaken, documenten) |
| documentatieLink | string | No | URL to API documentation for this channel |
| filters | string | No | Available filter attributes for this channel (JSON-encoded array) |

---

## mapLayer
**Purpose:** GIS map layer configuration for case maps — defines tile, WMS, WFS, or GeoJSON layers that can be displayed on case map views
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Display name for the layer in the layer switcher |
| layerType | string | Yes | The type of map layer (tile, wms, wfs, or geojson) |
| url | string | Yes | Service URL (tile template, WMS base URL, WFS endpoint, or GeoJSON URL) |
| layers | string | No | WMS/WFS layer name(s), comma-separated |
| format | string | No | Image format for WMS (e.g., image/png) |
| attribution | string | No | Attribution text for the layer |
| isDefault | boolean | No | Whether to show this layer on initial load |
| isBaseLayer | boolean | No | If true, only one base layer visible at a time |
| opacity | number | No | Layer opacity from 0.0 (transparent) to 1.0 (opaque) |
| minZoom | integer | No | Minimum zoom level for visibility |
| maxZoom | integer | No | Maximum zoom level for visibility |
| order | integer | No | Display order in the layer switcher |
| style | string | No | JSON-encoded style object for GeoJSON/WFS features (color, weight, fillColor, fillOpacity) |
| proxyEnabled | boolean | No | Whether to route requests through the backend GIS proxy (for CORS-restricted services) |

---

## objection
**Schema.org type:** `schema:Message`
**Purpose:** Bezwaarschrift (objection letter) — captures the formal objection content linked to a bezwaar case and the contested decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this objection belongs to |
| contestedDecision | string | Yes | The original besluit being contested |
| grounds | string | Yes | The grounds for objection (gronden van bezwaar) |
| requestedRelief | string | No | What outcome the bezwaarmaker seeks |
| receivedDate | string | Yes | Date the bezwaarschrift was received |
| receivedChannel | string | Yes | How the bezwaarschrift was received |
| isTimely | boolean | No | Whether the objection was filed within the 6-week term (Awb art. 6:7) |
| timelinessAssessment | string | No | Explanation of timeliness determination |
| proVoorziening | boolean | No | Whether a voorlopige voorziening (interim relief) was requested |
| attachments | string | No | JSON-encoded array of document references uploaded by bezwaarmaker |

**Relations:**
- → case (many-to-one)
- → decision (many-to-one)

---

## parafeeractie
**Schema.org type:** `schema:Action`
**Purpose:** An immutable record of a parafering action on a voorstel step
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | string | Yes | Reference to the voorstel |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | string | No | Whether the actor acted directly or as delegate |
| onBehalfOf | string | No | Nextcloud user UID of the principal (if acting as delegate) |
| action | string | Yes | The action performed |
| comment | string | No | Comment or reason (mandatory for returned/skipped) |
| advice | string | No | Advisory text (for advies steps) |
| mandate | string | No | Mandate reference (for delegate actions) |

**Relations:**
- → voorstel (many-to-one)

---

## parafeerroute
**Schema.org type:** `schema:HowTo`
**Purpose:** A configurable endorsement route defining the sequence of parafering steps for a voorstel
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this parafeerroute (e.g. Collegeadvies - Omgevingsvergunning) |
| caseType | string | No | Reference to the case type this route is associated with |
| voorstelType | string | No | Voorstel type this route applies to |
| steps | array | Yes | Ordered list of parafering steps |
| isDefault | boolean | No | Whether this is the default route for the linked case type and voorstel type |
| description | string | No | Description of when this route should be used |

---

## propertyDefinition
**Schema.org type:** `schema:PropertyValueSpecification`
**Purpose:** Custom field definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this custom property |
| definition | string | No | Short definition of this property |
| description | string | No | Longer explanation of this property |
| caseType | string | Yes | Reference to the parent case type |
| propertyType | string | No | Data type of this property |
| isRequired | boolean | No | Whether this property is required on cases |
| defaultValue | string | No | Default value for this property |

---

## result
**Schema.org type:** `schema:Thing`
**Purpose:** A case outcome record
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | No | Name of this result |
| case | string | Yes | Reference to the case |
| resultType | string | Yes | Reference to the result type |
| description | string | No | Description of this result |

**Relations:**
- → case (many-to-one)

---

## resultType
**Schema.org type:** `schema:Thing`
**Purpose:** Case outcome type with archival rules
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this result type (e.g. Vergunning verleend) |
| description | string | No | Description/toelichting of this result type |
| genericDescription | string | No | Generic description derived from selectielijst resultaattypeomschrijving |
| caseType | string | Yes | Reference to the parent case type |
| archivalPeriod | string | No | ISO 8601 duration for archival retention |
| archivalAction | string | No | What to do after archival period: keep or destroy |
| sourceDateArchiveProcedure | string | No | BrondatumArchiefprocedure configuration (JSON-encoded object with afleidingswijze, procestermijn, datumkenmerk, etc.) |
| selectionListClass | string | No | URL to the selectielijstklasse |

---

## role
**Schema.org type:** `schema:Role`
**Purpose:** A role assignment on a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Display name for this role assignment |
| roleType | string | Yes | Reference to the role type |
| case | string | Yes | Reference to the case |
| participant | string | Yes | Nextcloud user ID or contact reference |
| description | string | No | Description of this role assignment |

**Relations:**
- → case (many-to-one)

---

## roleType
**Schema.org type:** `schema:Role`
**Purpose:** Participant role type definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this role type (e.g. Behandelaar, Adviseur) |
| description | string | No | Description of this role type |
| caseType | string | Yes | Reference to the parent case type |

---

## statusRecord
**Schema.org type:** `schema:Event`
**Purpose:** A status transition record for a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| statusType | string | Yes | Reference to the status type |
| description | string | No | Status transition description |

**Relations:**
- → case (many-to-one)

---

## statusType
**Schema.org type:** `schema:ActionStatusType`
**Purpose:** Status lifecycle phase definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this status (e.g. Ontvangen, In behandeling) |
| description | string | No | Description of this status phase |
| caseType | string | Yes | Reference to the parent case type |
| order | integer | Yes | Position in the status lifecycle (lower = earlier) |
| isFinal | boolean | No | Whether this is a terminal/final status |

---

## task
**Schema.org type:** `schema:Action`
**Purpose:** A task within a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of this task |
| description | string | No | Detailed description of this task |
| status | string | No | Task status (CMMN HumanTask lifecycle) |
| case | string | Yes | Reference to the parent case |
| assignee | string | No | Nextcloud user ID of the assigned user |
| dueDate | string | No | Due date for this task |
| priority | string | No | Task priority |
| completedDate | string | No | Date the task was completed |
| workflowStepId | string | No | UUID of the workflow step that generated this task |
| checklist | string | No | JSON-encoded array of checklist items ({id, label, checked}) |

**Relations:**
- → case (many-to-one)

---

## usageRights
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Usage rights (gebruiksrechten) for a document
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| startDate | string | Yes | Start date of the usage rights |
| endDate | string | No | End date of the usage rights |
| conditionsDescription | string | Yes | Description of the usage conditions |

---

## voorstel
**Schema.org type:** `schema:CreativeWork`
**Purpose:** A B&W voorstel (proposal) for decision-making in a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the parent case |
| type | string | Yes | Type of voorstel (DT-advies, Collegeadvies, Raadsvoorstel) |
| onderwerp | string | Yes | Subject of the voorstel (usually derived from case title) |
| steller | string | Yes | Nextcloud user UID who created the voorstel |
| afdeling | string | No | Department of the steller |
| portefeuillehouder | string | No | Nextcloud user UID of the responsible portfolio holder (wethouder) |
| status | string | Yes | Current status of the voorstel in the parafering lifecycle |
| parafeerroute | string | No | Reference to the parafeerroute being used |
| routeSnapshot | string | No | Snapshot of the parafeerroute steps at submission time (JSON-encoded array) |
| currentStep | integer | No | Current step number in the parafeerroute (1-based, 0 = not yet submitted) |
| returnedFromStep | integer | No | Step number from which the voorstel was returned (for resume on resubmit) |
| document | string | No | Nextcloud file ID of the primary voorstel document |
| bijlagen | array | No | Nextcloud file IDs of attached documents (bijlagen) |
| behandeling | string | No | Treatment type in the college meeting |
| decision | string | No | Reference to the linked decision (set when besluit is registered) |

**Relations:**
- → case (many-to-one)

---

## workflowTemplate
**Schema.org type:** `schema:HowTo`
**Purpose:** A workflow definition for a case type — defines process steps, status transitions, guards, and automatic actions
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Name of this workflow template |
| description | string | No | Purpose and usage notes for this workflow |
| caseType | string | Yes | Reference to the case type this workflow belongs to |
| version | integer | No | Auto-incrementing version number |
| isActive | boolean | No | Whether this is the active version for new cases |
| isDraft | boolean | No | Draft templates cannot be used for new cases |
| steps | string | No | JSON-encoded array of WorkflowStep objects. Each step has: id (UUID), title, description, status (UUID ref to statusType), order (integer), assigneeRole (UUID ref to roleType, optional), isRequired (boolean), checklist (array of {id, label, description}), automaticActions (array of ActionRef) |
| transitions | string | No | JSON-encoded array of StatusTransition objects. Each transition has: id (UUID), fromStatus (UUID), toStatus (UUID), label (string), guards (array of Guard), automaticActions (array of ActionRef), allowedRoles (array of UUID). Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify |
| nodePositions | string | No | JSON-encoded map of status UUID to {x, y} canvas positions for the visual editor |
| parentWorkflow | string | No | Reference to parent workflow template for inheritance (Enterprise tier) |

**Relations:**
- → caseType (many-to-one)

---

## zaaktypeInformatieobjecttype
**Schema.org type:** `schema:Thing`
**Purpose:** Links a case type to a document type with direction and ordering
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | string | Yes | Reference to the case type |
| informatieobjecttype | string | Yes | Reference to the document type |
| volgnummer | integer | Yes | Ordering number |
| richting | string | Yes | Direction of the document in the case |
| statustype | string | No | Reference to a status type |

---


## App Architecture ADRs from Repo (1 files)

These ADR files live in procest/openspec/architecture/.

### ADR-000-data-model
# Data Model — Procest

**App:** Procest — Case management, VTH, forms
**Platform:** OpenRegister (register/schema/object pattern)
**Entities:** 39

OpenRegister built-in fields available on ALL entities (do NOT redefine):
id, uuid, uri, version, createdAt, updatedAt, owner, organization,
register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

OpenRegister built-in capabilities (do NOT rebuild):
CRUD REST API, CSV/JSON/XML import+export, full-text search, filtering,
pagination, audit trails, file attachments, relation management, locking.

---

## abonnement
**Schema.org type:** `schema:SubscribeAction`
**Purpose:** A subscription (abonnement) for receiving ZGW notifications
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callbackUrl | string | Yes | URL to POST notifications to |
| auth | string | Yes | Authorization header value for callback requests |
| kanalen | string | Yes | Channels and filters to subscribe to (JSON-encoded array) |

---

## adviesAanvraag
**Schema.org type:** `schema:AskAction`
**Purpose:** A request for internal or external advice on a case, with deadline tracking
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case this advice is requested for |
| adviseur | string | Yes | User UID (internal) or organization name (external) |
| type | string | Yes | Whether advice is from internal staff or external party |
| onderwerp | string | No | Subject/topic of the advice request |
| deadline | string | No | Deadline for receiving the advice |
| status | string | No | Current status of the advice request |
| adviesDocument | string | No | Nextcloud file ID of the advice document |
| requestedAt | string | No | Timestamp when the advice was requested |
| receivedAt | string | No | Timestamp when the advice was received |
| questions | string | No | Specific questions for the adviseur |

---

## advisoryReport
**Schema.org type:** `schema:Report`
**Purpose:** Advisory committee report (advies bezwaarschriftencommissie) — records the committee's advice on a bezwaar case per Awb art. 7:13
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this report belongs to |
| hearingSession | string | No | The hearing session this report is based on |
| committeeChair | string | Yes | Voorzitter who signed the report |
| committeeMembers | string | No | JSON-encoded array of committee member role UUIDs |
| adviceDate | string | Yes | Date the advice was issued |
| adviceType | string | Yes | Type of advice: upheld, rejected, partially upheld, inadmissible |
| summary | string | Yes | Summary of the committee's advice |
| grounds | string | Yes | Legal reasoning and grounds for the advice |
| recommendation | string | Yes | Recommended action for the bestuursorgaan |
| deviationFromPrimaryDecision | boolean | Yes | Whether the committee advises differently from the original decision |
| reportDocument | string | No | Reference to full advisory report document |

**Relations:**
- → case (many-to-one)
- → hearingSession (many-to-one)
- → role (many-to-one)

---

## appealDecision
**Schema.org type:** `schema:LegalForceStatus`
**Purpose:** Beslissing op bezwaar (decision on objection) — formal decision recording with disposition, motivation, and rechtsmiddelenclausule per Awb art. 7:11-7:12
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case |
| contestedDecision | string | Yes | The original besluit being contested |
| advisoryReport | string | No | The committee's advisory report |
| dispositionType | string | Yes | Decision outcome type |
| dispositionDetails | string | Yes | Detailed motivation for the decision (motiveringsplicht art. 7:12) |
| followsAdvice | boolean | No | Whether the decision follows the committee's advice |
| deviationReason | string | No | Reason for deviating from committee advice (required when followsAdvice is false) |
| remedialAction | string | No | Corrective action taken if gegrond/deels_gegrond |
| replacementDecision | string | No | New besluit that replaces the contested one |
| decisionDate | string | Yes | Date the decision was made |
| effectiveDate | string | Yes | Date the decision takes legal effect |
| appealInformation | string | Yes | Information about beroep possibilities (rechtsmiddelenclausule) |
| decisionMaker | string | Yes | The person/body that made the decision |
| decisionDocument | string | No | Reference to the formal decision letter document |

**Relations:**
- → case (many-to-one)
- → decision (many-to-one)
- → advisoryReport (many-to-one)
- → decision (many-to-one)
- → role (many-to-one)

---

## case
**Schema.org type:** `schema:Project`
**Purpose:** A case instance in the case management system
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of this case |
| description | string | No | Detailed description of this case |
| identifier | string | No | Auto-generated case identifier (e.g. 2026-0042) |
| caseType | string | Yes | Reference to the case type |
| status | string | No | Reference to the current status type |
| result | string | No | Reference to the result record (set on completion) |
| startDate | string | No | Date the case was started |
| endDate | string | No | Date the case was completed |
| plannedEndDate | string | No | Planned end date |
| deadline | string | No | Processing deadline |
| confidentiality | string | No | Confidentiality level |
| assignee | string | No | Nextcloud user ID of the primary handler |
| priority | string | No | Case priority |
| parentCase | string | No | Reference to parent case (for sub-cases) |
| relatedCases | string | No | References to related cases (JSON-encoded array) |
| geometry | string | No | GeoJSON geometry for location-based cases (JSON-encoded object) |
| statusHistory | string | No | History of status changes (JSON-encoded array) |
| activity | string | No | Activity log entries (JSON-encoded array) |
| extensionCount | integer | No | Number of deadline extensions applied |
| sourceOrganisation | string | No | RSIN of the organization that created this case |
| archiveNomination | string | No | Whether the case should be permanently archived or destroyed |
| archiveActionDate | string | No | Date when the archive action should be executed |
| archiveStatus | string | No | Current archive status of the case |
| paymentIndication | string | No | Payment status indicator |
| lastPaymentDate | string | No | Date of the last payment |
| communicationChannel | string | No | URL reference to the communication channel |
| workflowTemplate | string | No | Reference to the bound workflow template |
| workflowVersion | integer | No | Version number of the bound workflow template |

---

## caseDocument
**Purpose:** Links a document to a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| document | string | Yes | URI reference to the document |
| title | string | No | Title/description of the relation |
| description | string | No | Description of the relation |
| registrationDate | string | No | Registration date |

**Relations:**
- → case (many-to-one)

---

## caseObject
**Purpose:** Links an external object to a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| objectUrl | string | No | URL of the external object |
| objectType | string | Yes | Type of the external object |
| objectIdentification | string | No | JSON identification of the object |
| description | string | No | Description of the relation |

**Relations:**
- → case (many-to-one)

---

## caseProperty
**Purpose:** A property value on a specific case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| propertyDefinition | string | Yes | Reference to the property definition (eigenschap) |
| value | string | Yes | The property value |

**Relations:**
- → case (many-to-one)

---

## caseType
**Schema.org type:** `schema:Project`
**Purpose:** Case type definition — defines the blueprint for a category of cases including lifecycle, deadlines, and classification
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Name of this case type |
| description | string | No | Detailed description of this case type |
| identifier | string | No | Auto-generated identifier |
| catalogus | string | No | Reference to the parent catalogus |
| purpose | string | No | The purpose or goal of this case type |
| trigger | string | No | What triggers the creation of a case of this type |
| subject | string | No | The subject matter of this case type |
| processingDeadline | string | No | ISO 8601 duration for the processing deadline (e.g. P30D) |
| confidentiality | string | No | Confidentiality level |
| isDraft | boolean | No | Whether this case type is a draft (not yet published) |
| validFrom | string | No | Date from which this case type is valid |
| validUntil | string | No | Date until which this case type is valid (null = indefinite) |
| origin | string | No | Initiator action (e.g. indienen, aanvragen) |
| suspensionAllowed | boolean | No | Whether cases of this type can be suspended |
| extensionAllowed | boolean | No | Whether the processing deadline can be extended |
| extensionPeriod | string | No | ISO 8601 duration for extension period (e.g. P14D) |
| publicationRequired | boolean | No | Whether publication of the decision is required |
| internalOrExternal | string | No | Whether the case type is internal or external |
| handlerAction | string | No | Action performed by the handler |
| productsOrServices | string | No | URLs to products or services (JSON-encoded array) |
| selectionListProcessType | string | No | URL to the selection list process type |
| referenceProcess | string | No | Reference process definition (JSON-encoded object) |
| responsible | string | No | Responsible person or department |
| relatedCaseTypes | string | No | Related case types (JSON-encoded array) |
| subCaseTypes | array | No | References to sub-case types (deelzaaktypen) |
| decisionTypes | array | No | References to decision types (besluittypen) linked to this case type |

---

## catalogus
**Schema.org type:** `schema:DataCatalog`
**Purpose:** A catalogus groups case types, decision types, and document types
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| domein | string | Yes | Abbreviated domain name (max 5 characters) |
| rsin | string | No | RSIN of the responsible organisation |
| contactpersoonBeheerNaam | string | No | Name of the management contact |
| contactpersoonBeheerTelefoonnummer | string | No | Phone number of the management contact |
| contactpersoonBeheerEmailadres | string | No | Email of the management contact |

---

## customerContact
**Purpose:** A customer contact moment for a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| contactDateTime | string | No | Date-time of the contact |
| channel | string | No | Communication channel |
| subject | string | No | Subject of the contact |
| initiator | string | No | Who initiated the contact |

**Relations:**
- → case (many-to-one)

---

## decision
**Schema.org type:** `schema:ChooseAction`
**Purpose:** A formal decision on a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | No | Title of this decision |
| case | string | No | Reference to the case |
| description | string | No | Description of this decision |
| decisionType | string | No | Reference to the decision type |
| responsibleOrganisation | string | No | RSIN of the responsible organisation |
| decisionDate | string | No | Date the decision was made |
| effectiveDate | string | No | Date the decision takes effect |
| expiryDate | string | No | Date the decision expires |
| publicationDate | string | No | Publication date |
| deliveryDate | string | No | Delivery date |
| explanation | string | No | Explanation of the decision |
| governingBody | string | No | The governing body that made the decision (bestuursorgaan) |

**Relations:**
- → case (many-to-one)

---

## decisionDocument
**Purpose:** Links a document to a decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decision | string | Yes | Reference to the decision |
| document | string | Yes | URI reference to the document |

**Relations:**
- → decision (many-to-one)

---

## decisionType
**Schema.org type:** `schema:ChooseAction`
**Purpose:** Decision type definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this decision type |
| description | string | No | Description of this decision type |
| catalogus | string | No | Reference to the parent catalogus |
| caseType | string | No | Reference to the parent case type |
| isDraft | boolean | No | Whether this decision type is a draft (concept) |
| publicationRequired | boolean | No | Whether this decision type requires publication |
| caseTypes | array | No | References to case types (array of zaaktype URLs) |
| documentTypes | array | No | References to document types (array of informatieobjecttype URLs) |
| validFrom | string | No | Date from which this decision type is valid |
| validUntil | string | No | Date until which this decision type is valid |

---

## dispatch
**Purpose:** A document dispatch record
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document |
| involvedParty | string | No | URI of the involved party |
| relationshipType | string | Yes | Type of relationship (afzender/geadresseerde) |
| description | string | No | Description of the dispatch |
| receiveDate | string | No | Date received |
| sendDate | string | No | Date sent |
| contactPerson | string | No | Contact person URI |
| contactPersonName | string | No | Name of the contact person |

---

## document
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** A document (enkelvoudig informatieobject) in the document registry
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| identifier | string | No | Auto-generated document identifier |
| sourceOrganisation | string | No | RSIN of the source organisation |
| creationDate | string | No | Date the document was created |
| title | string | Yes | Title of this document |
| confidentiality | string | No | Confidentiality level |
| author | string | No | Author of the document |
| status | string | No | Document status |
| format | string | No | MIME type of the document (e.g. application/pdf) |
| language | string | No | Language of the document (ISO 639-2/B) |
| fileName | string | No | Original file name |
| fileSize | integer | No | File size in bytes |
| content | string | No | Base64-encoded file content or file reference |
| link | string | No | URL to the document |
| description | string | No | Description of the document |
| documentType | string | No | Reference to the document type |
| locked | boolean | No | Whether the document is locked for editing |
| lockId | string | No | Identifier of the current lock |
| fileParts | string | No | References to file parts for chunked uploads (JSON-encoded array) |
| usageRightsIndication | boolean | No | Indicates whether usage rights have been set for this document |

---

## documentLink
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** A link between a document and a case or decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| object | string | Yes | URI reference to the related object (zaak or besluit) |
| objectType | string | Yes | Type of the related object |

---

## documentType
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Document type requirement for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this document type (e.g. Situatietekening) |
| description | string | No | Description of this document type |
| catalogus | string | No | Reference to the parent catalogus |
| caseType | string | No | Reference to the parent case type |
| isDraft | boolean | No | Whether this document type is a draft (concept) |
| confidentiality | string | No | Confidentiality level |
| category | string | No | Document type category |
| isRequired | boolean | No | Whether this document is required for the case |
| allowedMimeTypes | string | No | Allowed MIME types (JSON-encoded array) |
| validFrom | string | No | Date from which this document type is valid |
| validUntil | string | No | Date until which this document type is valid |

---

## handhavingsactie
**Schema.org type:** `schema:LegalForceStatus`
**Purpose:** An enforcement action (handhavingsactie) on a case, classified per the Landelijke Handhavingsstrategie (LHS)
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the handhavingszaak |
| type | string | Yes | Type of enforcement action |
| ernst | string | Yes | Severity of the violation (LHS ernst axis) |
| gedrag | string | Yes | Behavior of the violator (LHS gedrag axis) |
| interventie | string | No | Suggested intervention from LHS matrix (may be overridden) |
| begunstigingstermijn | integer | No | Grace period in days before enforcement takes effect |
| dwangsomBedrag | number | No | Penalty amount per violation (EUR) |
| dwangsomMaximaal | number | No | Maximum total penalty amount (EUR) |
| effectueringsDatum | string | No | Date when enforcement action takes effect |
| status | string | No | Current status of the enforcement action |
| overrideReason | string | No | Documented reasoning if the LHS suggestion was overridden |

---

## hearingSession
**Schema.org type:** `schema:Event`
**Purpose:** Hoorzitting (hearing) — manages scheduling, invitations, and minutes for bezwaar hearings per Awb art. 7:2
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this hearing belongs to |
| scheduledDate | string | Yes | Date and time of the hearing |
| location | string | No | Physical location or 'Online' for video hearings |
| videoCallUrl | string | No | Video conference link for online hearings |
| chairperson | string | Yes | Who chairs the hearing (voorzitter) |
| members | string | No | JSON-encoded array of committee member role UUIDs |
| invitees | string | Yes | JSON-encoded array of invitee objects (name, role, email, status) |
| minutesSummary | string | No | Summary of what was discussed (verslag) |
| minutesDocument | string | No | Reference to full hearing minutes document |
| status | string | Yes | Hearing session status |
| hearingWaived | boolean | No | Bezwaarmaker has waived the right to be heard |
| waiverReason | string | No | Reason for waiving hearing right |

**Relations:**
- → case (many-to-one)
- → role (many-to-one)

---

## inspectieChecklist
**Schema.org type:** `schema:HowTo`
**Purpose:** Configurable inspection checklist template linked to a case type, with versioning support
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this checklist (e.g. 'Bouwtoezicht fase 1 - Fundering') |
| caseType | string | Yes | Reference to the case type this checklist belongs to |
| version | integer | No | Version number of this checklist (incremented on edit) |
| status | string | No | Lifecycle status of this checklist version |
| items | array | No | Ordered list of checklist items |

---

## inspectieRapport
**Schema.org type:** `schema:Report`
**Purpose:** A completed inspection report generated from a checklist, stored on the case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case (toezichtzaak) this report belongs to |
| checklist | string | Yes | Reference to the inspectieChecklist used |
| inspector | string | Yes | User UID of the inspector |
| inspectionDate | string | Yes | Date and time of the inspection |
| location | string | No | GPS coordinates or address of the inspection location |
| result | string | No | Overall inspection result (auto-calculated from items) |
| failedItems | integer | No | Count of failed checklist items |
| items | array | No | Completed checklist item results |
| photos | array | No | All Nextcloud file IDs of photos taken during inspection |
| remarks | string | No | General remarks about the inspection |
| followUpRequired | boolean | No | Whether follow-up action is required |

---

## kanaal
**Schema.org type:** `schema:BroadcastChannel`
**Purpose:** A notification channel (kanaal) for ZGW event distribution
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string | Yes | Name of this channel (e.g. zaken, documenten) |
| documentatieLink | string | No | URL to API documentation for this channel |
| filters | string | No | Available filter attributes for this channel (JSON-encoded array) |

---

## mapLayer
**Purpose:** GIS map layer configuration for case maps — defines tile, WMS, WFS, or GeoJSON layers that can be displayed on case map views
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Display name for the layer in the layer switcher |
| layerType | string | Yes | The type of map layer (tile, wms, wfs, or geojson) |
| url | string | Yes | Service URL (tile template, WMS base URL, WFS endpoint, or GeoJSON URL) |
| layers | string | No | WMS/WFS layer name(s), comma-separated |
| format | string | No | Image format for WMS (e.g., image/png) |
| attribution | string | No | Attribution text for the layer |
| isDefault | boolean | No | Whether to show this layer on initial load |
| isBaseLayer | boolean | No | If true, only one base layer visible at a time |
| opacity | number | No | Layer opacity from 0.0 (transparent) to 1.0 (opaque) |
| minZoom | integer | No | Minimum zoom level for visibility |
| maxZoom | integer | No | Maximum zoom level for visibility |
| order | integer | No | Display order in the layer switcher |
| style | string | No | JSON-encoded style object for GeoJSON/WFS features (color, weight, fillColor, fillOpacity) |
| proxyEnabled | boolean | No | Whether to route requests through the backend GIS proxy (for CORS-restricted services) |

---

## objection
**Schema.org type:** `schema:Message`
**Purpose:** Bezwaarschrift (objection letter) — captures the formal objection content linked to a bezwaar case and the contested decision
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | The bezwaar case this objection belongs to |
| contestedDecision | string | Yes | The original besluit being contested |
| grounds | string | Yes | The grounds for objection (gronden van bezwaar) |
| requestedRelief | string | No | What outcome the bezwaarmaker seeks |
| receivedDate | string | Yes | Date the bezwaarschrift was received |
| receivedChannel | string | Yes | How the bezwaarschrift was received |
| isTimely | boolean | No | Whether the objection was filed within the 6-week term (Awb art. 6:7) |
| timelinessAssessment | string | No | Explanation of timeliness determination |
| proVoorziening | boolean | No | Whether a voorlopige voorziening (interim relief) was requested |
| attachments | string | No | JSON-encoded array of document references uploaded by bezwaarmaker |

**Relations:**
- → case (many-to-one)
- → decision (many-to-one)

---

## parafeeractie
**Schema.org type:** `schema:Action`
**Purpose:** An immutable record of a parafering action on a voorstel step
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | string | Yes | Reference to the voorstel |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | string | No | Whether the actor acted directly or as delegate |
| onBehalfOf | string | No | Nextcloud user UID of the principal (if acting as delegate) |
| action | string | Yes | The action performed |
| comment | string | No | Comment or reason (mandatory for returned/skipped) |
| advice | string | No | Advisory text (for advies steps) |
| mandate | string | No | Mandate reference (for delegate actions) |

**Relations:**
- → voorstel (many-to-one)

---

## parafeerroute
**Schema.org type:** `schema:HowTo`
**Purpose:** A configurable endorsement route defining the sequence of parafering steps for a voorstel
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this parafeerroute (e.g. Collegeadvies - Omgevingsvergunning) |
| caseType | string | No | Reference to the case type this route is associated with |
| voorstelType | string | No | Voorstel type this route applies to |
| steps | array | Yes | Ordered list of parafering steps |
| isDefault | boolean | No | Whether this is the default route for the linked case type and voorstel type |
| description | string | No | Description of when this route should be used |

---

## propertyDefinition
**Schema.org type:** `schema:PropertyValueSpecification`
**Purpose:** Custom field definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this custom property |
| definition | string | No | Short definition of this property |
| description | string | No | Longer explanation of this property |
| caseType | string | Yes | Reference to the parent case type |
| propertyType | string | No | Data type of this property |
| isRequired | boolean | No | Whether this property is required on cases |
| defaultValue | string | No | Default value for this property |

---

## result
**Schema.org type:** `schema:Thing`
**Purpose:** A case outcome record
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | No | Name of this result |
| case | string | Yes | Reference to the case |
| resultType | string | Yes | Reference to the result type |
| description | string | No | Description of this result |

**Relations:**
- → case (many-to-one)

---

## resultType
**Schema.org type:** `schema:Thing`
**Purpose:** Case outcome type with archival rules
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this result type (e.g. Vergunning verleend) |
| description | string | No | Description/toelichting of this result type |
| genericDescription | string | No | Generic description derived from selectielijst resultaattypeomschrijving |
| caseType | string | Yes | Reference to the parent case type |
| archivalPeriod | string | No | ISO 8601 duration for archival retention |
| archivalAction | string | No | What to do after archival period: keep or destroy |
| sourceDateArchiveProcedure | string | No | BrondatumArchiefprocedure configuration (JSON-encoded object with afleidingswijze, procestermijn, datumkenmerk, etc.) |
| selectionListClass | string | No | URL to the selectielijstklasse |

---

## role
**Schema.org type:** `schema:Role`
**Purpose:** A role assignment on a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Display name for this role assignment |
| roleType | string | Yes | Reference to the role type |
| case | string | Yes | Reference to the case |
| participant | string | Yes | Nextcloud user ID or contact reference |
| description | string | No | Description of this role assignment |

**Relations:**
- → case (many-to-one)

---

## roleType
**Schema.org type:** `schema:Role`
**Purpose:** Participant role type definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this role type (e.g. Behandelaar, Adviseur) |
| description | string | No | Description of this role type |
| caseType | string | Yes | Reference to the parent case type |

---

## statusRecord
**Schema.org type:** `schema:Event`
**Purpose:** A status transition record for a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the case |
| statusType | string | Yes | Reference to the status type |
| description | string | No | Status transition description |

**Relations:**
- → case (many-to-one)

---

## statusType
**Schema.org type:** `schema:ActionStatusType`
**Purpose:** Status lifecycle phase definition for a case type
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Name of this status (e.g. Ontvangen, In behandeling) |
| description | string | No | Description of this status phase |
| caseType | string | Yes | Reference to the parent case type |
| order | integer | Yes | Position in the status lifecycle (lower = earlier) |
| isFinal | boolean | No | Whether this is a terminal/final status |

---

## task
**Schema.org type:** `schema:Action`
**Purpose:** A task within a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Title of this task |
| description | string | No | Detailed description of this task |
| status | string | No | Task status (CMMN HumanTask lifecycle) |
| case | string | Yes | Reference to the parent case |
| assignee | string | No | Nextcloud user ID of the assigned user |
| dueDate | string | No | Due date for this task |
| priority | string | No | Task priority |
| completedDate | string | No | Date the task was completed |
| workflowStepId | string | No | UUID of the workflow step that generated this task |
| checklist | string | No | JSON-encoded array of checklist items ({id, label, checked}) |

**Relations:**
- → case (many-to-one)

---

## usageRights
**Schema.org type:** `schema:DigitalDocument`
**Purpose:** Usage rights (gebruiksrechten) for a document
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | string | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| startDate | string | Yes | Start date of the usage rights |
| endDate | string | No | End date of the usage rights |
| conditionsDescription | string | Yes | Description of the usage conditions |

---

## voorstel
**Schema.org type:** `schema:CreativeWork`
**Purpose:** A B&W voorstel (proposal) for decision-making in a case
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the parent case |
| type | string | Yes | Type of voorstel (DT-advies, Collegeadvies, Raadsvoorstel) |
| onderwerp | string | Yes | Subject of the voorstel (usually derived from case title) |
| steller | string | Yes | Nextcloud user UID who created the voorstel |
| afdeling | string | No | Department of the steller |
| portefeuillehouder | string | No | Nextcloud user UID of the responsible portfolio holder (wethouder) |
| status | string | Yes | Current status of the voorstel in the parafering lifecycle |
| parafeerroute | string | No | Reference to the parafeerroute being used |
| routeSnapshot | string | No | Snapshot of the parafeerroute steps at submission time (JSON-encoded array) |
| currentStep | integer | No | Current step number in the parafeerroute (1-based, 0 = not yet submitted) |
| returnedFromStep | integer | No | Step number from which the voorstel was returned (for resume on resubmit) |
| document | string | No | Nextcloud file ID of the primary voorstel document |
| bijlagen | array | No | Nextcloud file IDs of attached documents (bijlagen) |
| behandeling | string | No | Treatment type in the college meeting |
| decision | string | No | Reference to the linked decision (set when besluit is registered) |

**Relations:**
- → case (many-to-one)

---

## workflowTemplate
**Schema.org type:** `schema:HowTo`
**Purpose:** A workflow definition for a case type — defines process steps, status transitions, guards, and automatic actions
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Name of this workflow template |
| description | string | No | Purpose and usage notes for this workflow |
| caseType | string | Yes | Reference to the case type this workflow belongs to |
| version | integer | No | Auto-incrementing version number |
| isActive | boolean | No | Whether this is the active version for new cases |
| isDraft | boolean | No | Draft templates cannot be used for new cases |
| steps | string | No | JSON-encoded array of WorkflowStep objects. Each step has: id (UUID), title, description, status (UUID ref to statusType), order (integer), assigneeRole (UUID ref to roleType, optional), isRequired (boolean), checklist (array of {id, label, description}), automaticActions (array of ActionRef) |
| transitions | string | No | JSON-encoded array of StatusTransition objects. Each transition has: id (UUID), fromStatus (UUID), toStatus (UUID), label (string), guards (array of Guard), automaticActions (array of ActionRef), allowedRoles (array of UUID). Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify |
| nodePositions | string | No | JSON-encoded map of status UUID to {x, y} canvas positions for the visual editor |
| parentWorkflow | string | No | Reference to parent workflow template for inheritance (Enterprise tier) |

**Relations:**
- → caseType (many-to-one)

---

## zaaktypeInformatieobjecttype
**Schema.org type:** `schema:Thing`
**Purpose:** Links a case type to a document type with direction and ordering
**Primary spec:** from-register

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | string | Yes | Reference to the case type |
| informatieobjecttype | string | Yes | Reference to the document type |
| volgnummer | integer | Yes | Ordering number |
| richting | string | Yes | Direction of the document in the case |
| statustype | string | No | Reference to a status type |

---
