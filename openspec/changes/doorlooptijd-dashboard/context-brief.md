# Context Brief: Doorlooptijd Dashboard Specification

**App:** Procest — Case management, VTH, forms
**Spec:** doorlooptijd-dashboard
**Platform:** Nextcloud + OpenRegister

## Features (2 total, sorted by market demand)

### Track the 6-week statutory bezwaar processing deadline
**demand: 147** (49 tender mentions) | Category: scheduling

### processing time reduction
**demand: 1** | Category: other

## User Stories

(No user stories linked to this spec. Generate from the features above.)

## Customer Journeys

(No journeys linked. Infer from stakeholders and features above.)

## Stakeholders

(No stakeholders linked. Infer from the features and user stories above.)

## Other App Entities (do NOT redefine)

abonnement, adviesAanvraag, advisoryReport, appealDecision, case, caseDocument, caseObject, caseProperty, caseType, catalogus, customerContact, decision, decisionDocument, decisionType, dispatch, document, documentLink, documentType, handhavingsactie, hearingSession, inspectieChecklist, inspectieRapport, kanaal, mapLayer, objection, parafeeractie, parafeerroute, propertyDefinition, result, resultType, role, roleType, statusRecord, statusType, task, usageRights, voorstel, workflowTemplate, zaaktypeInformatieobjecttype

## Company-Wide Architecture Rules (17 ADRs)

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
- Multi-tenant isolation: enforce at API/service level, not UI only.
- NO PII in logs, error responses, or debug output.
- Audit trails: use `$user->getUID()` — NEVER `$user->getDisplayName()` (mutable, spoofable).
- Identity: always derive from `IUserSession` on backend — NEVER trust frontend-sent user IDs or display names.
- File uploads: validate type + size before storage.
- API responses: NO stack traces, SQL, or internal paths.
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

## Consequences
- All apps maintain two translation files that must stay in sync.
- Dutch strings used as translation keys (e.g., `t('app', 'Besluiten')`) are a violation — the English equivalent must be the key.
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

| Priority | Type | Source | Container image | Model |
|----------|------|--------|-----------------|-------|
| 1 | **bugfix** | Hydra: fix iteration after review failure | `hydra-builder` | sonnet |
| 2 | **code-review** | Hydra: PR code review | `hydra-reviewer` | sonnet |
| 3 | **security-review** | Hydra: PR security review | `hydra-security` | sonnet |
| 4 | **build** | Hydra: initial spec build | `hydra-builder` | sonnet |
| 5 | **audit** | Hydra: codebase audit | `hydra-builder` | sonnet |
| 6 | **spec-generation** | Specter: push_spec_pipeline | `specter-llm-worker` | sonnet |
| 7 | **schema-synthesis** | Specter: generate/dedup schemas | `specter-llm-worker` | haiku |
| 8 | **classification** | Specter: classify/redistribute features | `specter-llm-worker` | haiku |
| 9 | **translation** | Specter: translate requirements | `specter-llm-worker` | haiku |
| 10 | **discovery** | Specter: research, feature extraction | `specter-llm-worker` | haiku |

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
| `hydra-reviewer:latest` | 1.3GB | Code review: Claude CLI + review skills |
| `hydra-security:latest` | 1.9GB | Security review: Claude CLI + Semgrep + security skills |
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

## Consequences

- All LLM calls go through containers — no direct `claude -p` from host scripts
- Token management is centralized per system (Specter has private fallback, Hydra doesn't)
- Container exit code determines token rotation (not mid-session JSONL text)
- Prebuild NC image eliminates 30-60s clone overhead per builder container
- Container images are the unit of deployment — version, test, rollback independently
- ADR-000 convention: every repo's data model is at `openspec/architecture/adr-000-data-model.md`
- `context-brief.md` in each change directory carries intelligence data through the full pipeline

### ADR-014-licensing
- Licence: EUPL-1.2 (European Union Public Licence). SPDX header on every source file.
- `appinfo/info.xml`: MUST use `<licence>agpl</licence>` — Nextcloud app store does not recognise EUPL.
- This is intentional dual-tagging, NOT a conflict. Do NOT change info.xml to eupl. Do NOT flag as review finding.
- PHP: `// SPDX-License-Identifier: EUPL-1.2` after `<?php` opening tag.
- Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line.
- JS: `// SPDX-License-Identifier: EUPL-1.2` as first line.
- File header block: `@licence EUPL-1.2`, `@copyright {year} Conduction B.V.`, `@link https://conduction.nl`

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

## App-Specific ADRs (2)

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


### adr-000-data-model: Data Model
# ADR-000: Data Model — Procest

**Status:** accepted
**Standard:** CMMN 1.1 (OMG) + Schema.org + ZGW API mapping
**Storage:** OpenRegister (JSON object store, no own tables)
**Entities:** 39 schemas across 7 groups

## Context

Procest is a case management (zaakgericht werken) app for Nextcloud. It follows the
**thin-client pattern**: the app owns no database tables. All data is stored as
OpenRegister objects, validated against schemas defined in `lib/Settings/procest_register.json`.

The data model uses a layered standards approach:

| Layer | Standard | Purpose |
|-------|----------|---------|
| Primary (storage) | CMMN 1.1 concepts + Schema.org vocabulary | International case model |
| Semantic | Schema.org JSON-LD | Type annotations for linked data |
| API mapping | ZGW/RGBZ field names | Dutch government interoperability |
| Type system reference | ZGW Catalogi API (ZaakType) | Case type behavioral controls |
| Nextcloud native | Calendar, Files, Activity, Talk | Reuse where possible |

**Design principle:** International standards first. ZGW/RGBZ is an API mapping layer,
not the storage model. This makes Procest usable outside the Netherlands while remaining
interoperable with Dutch government systems.

OpenRegister built-in fields (NOT listed in tables below, always available on every entity):
`id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`,
`register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`,
`status`, `locked`.

## Decision

### Entity Groups

1. **Type Definitions** — Catalogue-level blueprints: caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType
2. **Instance Entities** — Runtime case data: case, task, role, result, statusRecord, decision, document
3. **Link Entities** — Relation join tables: documentLink, caseDocument, caseObject, caseProperty, decisionDocument, zaaktypeInformatieobjecttype
4. **ZGW / Notification** — Catalogus, kanaal, abonnement, customerContact, dispatch, usageRights
5. **Voorstel / Parafering** — Internal approval workflow: voorstel, parafeerroute, parafeeractie
6. **Bezwaar / Beroep** — Objection lifecycle: objection, hearingSession, advisoryReport, appealDecision
7. **VTH / Enforcement** — Inspection and enforcement: adviesAanvraag, handhavingsactie, inspectieChecklist, inspectieRapport, mapLayer, workflowTemplate

---

## Group 1: Type Definitions

### caseType
**CMMN:** `CaseDefinition` / `CasePlanModel` template
**Schema.org:** `schema:Project`
**ZGW:** `ZaakType` (Catalogi API 1.3.x)
_Blueprint for a category of cases — controls lifecycle, deadlines, confidentiality, and archival._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Name of this case type (translatable) |
| description | string | No | Detailed description (translatable) |
| identifier | string | No | Auto-generated identifier |
| catalogus | uuid | No | Reference to parent catalogus |
| purpose | string | No | Goal of this case type (translatable) |
| trigger | string | No | What triggers creation of a case (translatable) |
| subject | string | No | Subject matter (translatable) |
| processingDeadline | string (ISO 8601 duration) | No | Processing deadline (e.g. P30D) |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| isDraft | boolean (default: true) | No | Whether this case type is a draft |
| validFrom | date | No | Date from which this case type is valid |
| validUntil | date | No | Date until which it is valid (null = indefinite) |
| origin | string | No | Initiator action (e.g. indienen, aanvragen) |
| suspensionAllowed | boolean (default: false) | No | Whether cases can be suspended |
| extensionAllowed | boolean (default: false) | No | Whether deadline can be extended |
| extensionPeriod | string (ISO 8601 duration) | No | Extension period duration |
| publicationRequired | boolean (default: false) | No | Whether publication of decision is required |
| internalOrExternal | enum | No | intern / extern |
| handlerAction | string | No | Action performed by the handler |
| productsOrServices | string (JSON array) | No | URLs to products or services |
| selectionListProcessType | uri | No | URL to selectielijst process type |
| referenceProcess | string (JSON object) | No | Reference process definition |
| responsible | string | No | Responsible person or department |
| relatedCaseTypes | string (JSON array) | No | Related case types |
| subCaseTypes | array of string | No | References to sub-case types (deelzaaktypen) |
| decisionTypes | array of string | No | References to linked decision types (besluittypen) |

**Relations:**
- → statusType (one-to-many): lifecycle phases
- → resultType (one-to-many): possible outcomes
- → roleType (one-to-many): participant role definitions
- → propertyDefinition (one-to-many): custom field definitions
- → documentType (one-to-many): required documents
- → decisionType (many-to-many): linked decision types
- → workflowTemplate (one-to-many): workflow processes

---

### statusType
**Schema.org:** `schema:ActionStatusType`
**ZGW:** `StatusType`
_Lifecycle phase definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this status (e.g. Ontvangen, In behandeling) (translatable) |
| description | string | No | Description of this status phase (translatable) |
| caseType | uuid | Yes | Reference to the parent case type |
| order | integer (default: 0) | Yes | Position in the lifecycle (lower = earlier) |
| isFinal | boolean (default: false) | No | Whether this is a terminal/final status |

**Relations:**
- → caseType (many-to-one)

---

### resultType
**Schema.org:** `schema:Thing`
**ZGW:** `ResultaatType`
_Case outcome type definition with archival retention rules._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this result type (e.g. Vergunning verleend) (translatable) |
| description | string | No | Description/toelichting (translatable) |
| genericDescription | string | No | Generic description from selectielijst resultaattypeomschrijving |
| caseType | uuid | Yes | Reference to the parent case type |
| archivalPeriod | string (ISO 8601 duration) | No | Archival retention period |
| archivalAction | enum | No | bewaren / vernietigen / blijvend_bewaren |
| sourceDateArchiveProcedure | string (JSON object) | No | BrondatumArchiefprocedure config (afleidingswijze, procestermijn, datumkenmerk) |
| selectionListClass | uri | No | URL to selectielijstklasse |

**Relations:**
- → caseType (many-to-one)

---

### roleType
**Schema.org:** `schema:Role`
**ZGW:** `RolType`
_Participant role type definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the role (e.g. Behandelaar, Adviseur) (translatable) |
| description | string | No | Description of this role type (translatable) |
| caseType | uuid | Yes | Reference to the parent case type |

**Relations:**
- → caseType (many-to-one)

---

### propertyDefinition
**Schema.org:** `schema:PropertyValueSpecification`
**ZGW:** `Eigenschap`
_Custom field definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the custom property |
| definition | string | No | Short definition |
| description | string | No | Longer explanation |
| caseType | uuid | Yes | Reference to the parent case type |
| propertyType | enum | No | string / number / boolean / date / url / email |
| isRequired | boolean (default: false) | No | Whether required on cases |
| defaultValue | string | No | Default value |

**Relations:**
- → caseType (many-to-one)

---

### documentType
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `InformatieObjectType`
_Document type requirement definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the document type (translatable) |
| description | string | No | Description (translatable) |
| catalogus | uuid | No | Reference to parent catalogus |
| caseType | uuid | No | Reference to the parent case type |
| isDraft | boolean (default: true) | No | Whether this is a draft (concept) |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| category | string | No | Document type category |
| isRequired | boolean (default: false) | No | Whether this document is required |
| allowedMimeTypes | string (JSON array) | No | Allowed MIME types |
| validFrom | date | No | Date from which valid |
| validUntil | date | No | Date until which valid |

**Relations:**
- → caseType (many-to-one)

---

### decisionType
**Schema.org:** `schema:ChooseAction`
**ZGW:** `BesluitType`
_Decision type definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this decision type (translatable) |
| description | string | No | Description (translatable) |
| catalogus | uuid | No | Reference to parent catalogus |
| caseType | uuid | No | Reference to the parent case type |
| isDraft | boolean (default: true) | No | Whether this is a draft |
| publicationRequired | boolean (default: false) | No | Whether publication is required |
| caseTypes | array of string | No | References to case types (zaaktype URLs) |
| documentTypes | array of string | No | References to document types (informatieobjecttype URLs) |
| validFrom | date | No | Date from which valid |
| validUntil | date | No | Date until which valid |

**Relations:**
- → caseType (many-to-one)

---

## Group 2: Instance Entities

### case
**CMMN:** `CaseInstance` (runtime)
**Schema.org:** `schema:Project`
**ZGW:** `Zaak`
_A case instance in the case management system._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Title of this case |
| description | string | No | Detailed description |
| identifier | string | No | Auto-generated case identifier (e.g. 2026-0042) |
| caseType | uuid (facetable) | Yes | Reference to the case type |
| status | uuid (facetable) | No | Reference to the current status type |
| result | uuid | No | Reference to the result record (set on completion) |
| startDate | date | No | Date the case was started |
| endDate | date | No | Date the case was completed |
| plannedEndDate | date | No | Planned end date |
| deadline | date | No | Processing deadline |
| confidentiality | enum (facetable) | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| assignee | string (facetable) | No | Nextcloud user ID of the primary handler |
| priority | enum (facetable, default: normal) | No | low / normal / high / urgent |
| parentCase | uuid | No | Reference to parent case (for sub-cases / deelzaken) |
| relatedCases | string (JSON array) | No | References to related cases |
| geometry | string (JSON object) | No | GeoJSON geometry for location-based cases |
| statusHistory | string (JSON array) | No | History of status changes |
| activity | string (JSON array) | No | Activity log entries |
| extensionCount | integer (default: 0) | No | Number of deadline extensions applied |
| sourceOrganisation | string (max 9) | No | RSIN of the creating organization |
| archiveNomination | enum | No | blijvend_bewaren / vernietigen |
| archiveActionDate | date | No | Date when archive action executes |
| archiveStatus | enum | No | nog_te_archiveren / gearchiveerd / gearchiveerd_procestermijn_onbekend / overgedragen |
| paymentIndication | enum | No | nvt / nog_niet / gedeeltelijk / geheel |
| lastPaymentDate | date | No | Date of last payment |
| communicationChannel | uri | No | URL reference to communication channel |
| workflowTemplate | uuid | No | Reference to the bound workflow template |
| workflowVersion | integer | No | Version number of the bound workflow template |

**Relations:**
- → caseType (many-to-one)
- → statusType (many-to-one, current status)
- → task (one-to-many, CASCADE delete)
- → role (one-to-many, CASCADE delete)
- → result (one-to-one, CASCADE delete)
- → statusRecord (one-to-many, CASCADE delete)
- → decision (one-to-many, CASCADE delete)
- → caseDocument (one-to-many, CASCADE delete)
- → caseObject (one-to-many, CASCADE delete)
- → caseProperty (one-to-many, CASCADE delete)
- → customerContact (one-to-many, CASCADE delete)
- → voorstel (one-to-many, CASCADE delete)

---

### task
**CMMN:** `HumanTask`
**Schema.org:** `schema:Action`
**ZGW:** `Taak`
_A task within a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Title of this task |
| description | string | No | Detailed description |
| status | enum (facetable, default: available) | No | available / active / completed / terminated / disabled (CMMN HumanTask lifecycle) |
| case | uuid (CASCADE delete) | Yes | Reference to the parent case |
| assignee | string (facetable) | No | Nextcloud user ID of the assigned user |
| dueDate | date-time | No | Due date for this task |
| priority | enum (facetable, default: normal) | No | low / normal / high / urgent |
| completedDate | date-time | No | Date the task was completed |
| workflowStepId | string | No | UUID of the workflow step that generated this task |
| checklist | string (JSON array) | No | Checklist items ({id, label, checked}) |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### role
**Schema.org:** `schema:Role`
**ZGW:** `Rol`
_A role assignment on a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Display name for this role assignment |
| roleType | uuid | Yes | Reference to the role type |
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| participant | string | Yes | Nextcloud user ID or contact reference |
| description | string | No | Description of this role assignment |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → roleType (many-to-one)

---

### result
**Schema.org:** `schema:Thing`
**ZGW:** `Resultaat`
_A case outcome record._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | No | Name of this result |
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| resultType | uuid | Yes | Reference to the result type |
| description | string | No | Description of this result |

**Relations:**
- → case (one-to-one, CASCADE delete)
- → resultType (many-to-one)

---

### statusRecord
**Schema.org:** `schema:Event`
**ZGW:** `Status`
_A status transition record for a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| statusType | uuid | Yes | Reference to the status type |
| description | string | No | Status transition description |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → statusType (many-to-one)

---

### decision
**Schema.org:** `schema:ChooseAction`
**ZGW:** `Besluit`
_A formal decision on a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | No | Title of this decision |
| case | uuid (CASCADE delete) | No | Reference to the case |
| description | string | No | Description of this decision |
| decisionType | uuid | No | Reference to the decision type |
| responsibleOrganisation | string | No | RSIN of the responsible organisation |
| decisionDate | date | No | Date the decision was made |
| effectiveDate | date | No | Date the decision takes effect |
| expiryDate | date | No | Date the decision expires |
| publicationDate | date | No | Publication date |
| deliveryDate | date | No | Delivery date |
| explanation | string | No | Explanation of the decision |
| governingBody | string | No | Governing body (bestuursorgaan) that made the decision |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decisionType (many-to-one)
- → decisionDocument (one-to-many, CASCADE delete)
- → objection (one-to-many, via contestedDecision)
- → appealDecision (one-to-many, via contestedDecision)

---

### document
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `EnkelvoudigInformatieObject`
_A document (enkelvoudig informatieobject) in the document registry._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| identifier | string | No | Auto-generated document identifier |
| sourceOrganisation | string | No | RSIN of the source organisation |
| creationDate | date | No | Date the document was created |
| title | string (max 255) | Yes | Title of this document |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| author | string | No | Author of the document |
| status | enum | No | in_bewerking / ter_vaststelling / definitief / gearchiveerd |
| format | string | No | MIME type (e.g. application/pdf) |
| language | string (default: nld) | No | ISO 639-2/B language code |
| fileName | string | No | Original file name |
| fileSize | integer | No | File size in bytes |
| content | string | No | Base64-encoded file content or file reference |
| link | uri | No | URL to the document |
| description | string | No | Description of the document |
| documentType | uuid | No | Reference to the document type |
| locked | boolean (default: false) | No | Whether the document is locked for editing |
| lockId | string | No | Identifier of the current lock |
| fileParts | string (JSON array) | No | References to file parts for chunked uploads |
| usageRightsIndication | boolean (nullable) | No | Whether usage rights have been set |

**Relations:**
- → documentType (many-to-one)
- → caseDocument (one-to-many, via case linking)
- → decisionDocument (one-to-many, via decision linking)

---

## Group 3: Link Entities

### documentLink
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `ObjectInformatieObject`
_A link between a document and a case or decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| object | uri | Yes | URI reference to the related object (zaak or besluit) |
| objectType | enum | Yes | zaak / besluit |

---

### caseDocument
**ZGW:** `ZaakInformatieObject`
_Links a document to a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| document | uri | Yes | URI reference to the document |
| title | string | No | Title/description of the relation |
| description | string | No | Description of the relation |
| registrationDate | date | No | Registration date |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### caseObject
**ZGW:** `ZaakObject`
_Links an external object to a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| objectUrl | uri | No | URL of the external object |
| objectType | string | Yes | Type of the external object |
| objectIdentification | string | No | JSON identification of the object |
| description | string | No | Description of the relation |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### caseProperty
**ZGW:** `ZaakEigenschap`
_A custom property value on a specific case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| propertyDefinition | uuid | Yes | Reference to the property definition (eigenschap) |
| value | string | Yes | The property value |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → propertyDefinition (many-to-one)

---

### decisionDocument
**ZGW:** `BesluitInformatieObject`
_Links a document to a decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decision | uuid (CASCADE delete) | Yes | Reference to the decision |
| document | uri | Yes | URI reference to the document |

**Relations:**
- → decision (many-to-one, CASCADE delete)

---

### zaaktypeInformatieobjecttype
**ZGW:** `ZaakTypeInformatieObjectType`
_Links a case type to a document type with direction and ordering._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | uuid | Yes | Reference to the case type |
| informatieobjecttype | uuid | Yes | Reference to the document type |
| volgnummer | integer | Yes | Ordering number |
| richting | enum | Yes | inkomend / intern / uitgaand |
| statustype | uuid | No | Reference to a status type |

---

## Group 4: ZGW / Notification Entities

### catalogus
**Schema.org:** `schema:DataCatalog`
**ZGW:** `Catalogus`
_Groups case types, decision types, and document types._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| domein | string (max 5) | Yes | Abbreviated domain name |
| rsin | string (max 9) | No | RSIN of the responsible organisation |
| contactpersoonBeheerNaam | string (max 40) | No | Name of the management contact |
| contactpersoonBeheerTelefoonnummer | string (max 20) | No | Phone number of the management contact |
| contactpersoonBeheerEmailadres | string (max 254) | No | Email of the management contact |

---

### kanaal
**Schema.org:** `schema:BroadcastChannel`
**ZGW:** `Kanaal`
_A notification channel for ZGW event distribution._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string (max 50) | Yes | Name of this channel (e.g. zaken, documenten) |
| documentatieLink | uri | No | URL to API documentation for this channel |
| filters | string (JSON array) | No | Available filter attributes for this channel |

---

### abonnement
**Schema.org:** `schema:SubscribeAction`
**ZGW:** `Abonnement`
_A subscription for receiving ZGW notifications._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callbackUrl | uri | Yes | URL to POST notifications to |
| auth | string | Yes | Authorization header value for callback requests |
| kanalen | string (JSON array) | Yes | Channels and filters to subscribe to |

---

### customerContact
**ZGW:** `KlantContact`
_A customer contact moment for a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| contactDateTime | date-time | No | Date-time of the contact |
| channel | string | No | Communication channel |
| subject | string | No | Subject of the contact |
| initiator | string | No | Who initiated the contact |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### dispatch
**ZGW:** `Verzending`
_A document dispatch record (verzending)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document |
| involvedParty | uri | No | URI of the involved party |
| relationshipType | string | Yes | Type of relationship (afzender/geadresseerde) |
| description | string | No | Description of the dispatch |
| receiveDate | date | No | Date received |
| sendDate | date | No | Date sent |
| contactPerson | uri | No | Contact person URI |
| contactPersonName | string | No | Name of the contact person |

---

### usageRights
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `GebruiksRechten`
_Usage rights (gebruiksrechten) for a document._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| startDate | string | Yes | Start date of the usage rights |
| endDate | string | No | End date of the usage rights |
| conditionsDescription | string | Yes | Description of the usage conditions |

---

## Group 5: Voorstel / Parafering

### voorstel
**Schema.org:** `schema:CreativeWork`
_A B&W voorstel (proposal) for decision-making in a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the parent case |
| type | enum (facetable) | Yes | dt_advies / collegeadvies / raadsvoorstel |
| onderwerp | string (max 255) | Yes | Subject (usually derived from case title) |
| steller | string (facetable) | Yes | Nextcloud user UID who created the voorstel |
| afdeling | string | No | Department of the steller |
| portefeuillehouder | string | No | Nextcloud user UID of the portfolio holder (wethouder) |
| status | enum (facetable, default: concept) | Yes | concept / in_parafering / ter_accordering / geaccordeerd / aangeboden / besloten / gearchiveerd / teruggestuurd |
| parafeerroute | uuid | No | Reference to the parafeerroute being used |
| routeSnapshot | string (JSON array) | No | Snapshot of parafeerroute steps at submission time |
| currentStep | integer (default: 0) | No | Current step in the parafeerroute (1-based, 0 = not yet submitted) |
| returnedFromStep | integer | No | Step from which the voorstel was returned |
| document | string | No | Nextcloud file ID of the primary voorstel document |
| bijlagen | array of string | No | Nextcloud file IDs of attached documents |
| behandeling | enum | No | hamerstuk / bespreekstuk |
| decision | uuid | No | Reference to the linked decision (set when besluit is registered) |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → parafeerroute (many-to-one)
- → parafeeractie (one-to-many, CASCADE delete)

---

### parafeerroute
**Schema.org:** `schema:HowTo`
_A configurable endorsement route defining the sequence of parafering steps for a voorstel._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this route (e.g. Collegeadvies - Omgevingsvergunning) |
| caseType | uuid (facetable) | No | Reference to the case type this route is associated with |
| voorstelType | enum | No | dt_advies / collegeadvies / raadsvoorstel |
| steps | array of objects | Yes | Ordered list of parafering steps ({order, type, actor, actorType, mandatory, label}) |
| isDefault | boolean (default: false) | No | Whether this is the default route for the linked case type and voorstel type |
| description | string | No | Description of when this route should be used |

**Step types:** advies / parafering / accordering
**Actor types:** user / group / role

---

### parafeeractie
**Schema.org:** `schema:Action`
_An immutable record of a parafering action on a voorstel step._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | uuid (CASCADE delete) | Yes | Reference to the voorstel |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | enum (default: user) | No | user / delegate |
| onBehalfOf | string | No | Nextcloud user UID of the principal (if acting as delegate) |
| action | enum (facetable) | Yes | parafered / returned / advised / skipped |
| comment | string | No | Comment or reason (mandatory for returned/skipped) |
| advice | string | No | Advisory text (for advies steps) |
| mandate | string | No | Mandate reference (for delegate actions) |

**Relations:**
- → voorstel (many-to-one, CASCADE delete)

---

## Group 6: Bezwaar / Beroep

### objection
**Schema.org:** `schema:Message`
**ZGW:** `Bezwaarschrift`
_Bezwaarschrift (objection letter) — formal objection content linked to a bezwaar case and the contested decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this objection belongs to |
| contestedDecision | uuid | Yes | The original besluit being contested |
| grounds | string | Yes | Grounds for objection (gronden van bezwaar) |
| requestedRelief | string | No | What outcome the bezwaarmaker seeks |
| receivedDate | date | Yes | Date the bezwaarschrift was received |
| receivedChannel | enum | Yes | brief / email / formulier / balie |
| isTimely | boolean | No | Whether objection was filed within the 6-week term (Awb art. 6:7) |
| timelinessAssessment | string | No | Explanation of timeliness determination |
| proVoorziening | boolean (default: false) | No | Whether voorlopige voorziening (interim relief) was requested |
| attachments | string (JSON array) | No | Document references uploaded by bezwaarmaker |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decision (many-to-one, via contestedDecision)

---

### hearingSession
**Schema.org:** `schema:Event`
**ZGW:** `Hoorzitting`
_Manages scheduling, invitations, and minutes for bezwaar hearings per Awb art. 7:2._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this hearing belongs to |
| scheduledDate | date-time | Yes | Date and time of the hearing |
| location | string | No | Physical location or 'Online' for video hearings |
| videoCallUrl | uri | No | Video conference link for online hearings |
| chairperson | uuid (→ role) | Yes | Who chairs the hearing (voorzitter) |
| members | string (JSON array) | No | Committee member role UUIDs |
| invitees | string (JSON array) | Yes | Invitee objects (name, role, email, status) |
| minutesSummary | string | No | Summary of what was discussed (verslag) |
| minutesDocument | uuid | No | Reference to full hearing minutes document |
| status | enum (default: gepland) | Yes | gepland / uitgenodigd / uitgevoerd / geannuleerd / afgezien |
| hearingWaived | boolean (default: false) | No | Bezwaarmaker has waived the right to be heard |
| waiverReason | string | No | Reason for waiving hearing right |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → role (many-to-one, via chairperson)

---

### advisoryReport
**Schema.org:** `schema:Report`
**ZGW:** `AdviesBezwaarschriftencommissie`
_Advisory committee report per Awb art. 7:13._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this report belongs to |
| hearingSession | uuid (→ hearingSession) | No | The hearing session this report is based on |
| committeeChair | uuid (→ role) | Yes | Voorzitter who signed the report |
| committeeMembers | string (JSON array) | No | Committee member role UUIDs |
| adviceDate | date | Yes | Date the advice was issued |
| adviceType | enum | Yes | gegrond / ongegrond / deels_gegrond / niet_ontvankelijk |
| summary | string | Yes | Summary of the committee's advice |
| grounds | string | Yes | Legal reasoning and grounds for the advice |
| recommendation | string | Yes | Recommended action for the bestuursorgaan |
| deviationFromPrimaryDecision | boolean | Yes | Whether committee advises differently from original decision |
| reportDocument | uuid | No | Reference to full advisory report document |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → hearingSession (many-to-one)
- → role (many-to-one, via committeeChair)

---

### appealDecision
**Schema.org:** `schema:LegalForceStatus`
**ZGW:** `BeslissingOpBezwaar`
_Beslissing op bezwaar — formal decision recording with disposition and rechtsmiddelenclausule per Awb art. 7:11-7:12._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case |
| contestedDecision | uuid (→ decision) | Yes | The original besluit being contested |
| advisoryReport | uuid (→ advisoryReport) | No | The committee's advisory report |
| dispositionType | enum | Yes | gegrond / ongegrond / deels_gegrond / niet_ontvankelijk |
| dispositionDetails | string | Yes | Detailed motivation (motiveringsplicht art. 7:12) |
| followsAdvice | boolean | No | Whether the decision follows the committee's advice |
| deviationReason | string | No | Reason for deviating from committee advice |
| remedialAction | string | No | Corrective action if gegrond/deels_gegrond |
| replacementDecision | uuid (→ decision) | No | New besluit that replaces the contested one |
| decisionDate | date | Yes | Date the decision was made |
| effectiveDate | date | Yes | Date the decision takes legal effect |
| appealInformation | string | Yes | Beroep possibilities (rechtsmiddelenclausule) |
| decisionMaker | uuid (→ role) | Yes | The person/body that made the decision |
| decisionDocument | uuid | No | Reference to the formal decision letter document |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decision (many-to-one, via contestedDecision)
- → advisoryReport (many-to-one)
- → role (many-to-one, via decisionMaker)

---

## Group 7: VTH / Enforcement

### workflowTemplate
**Schema.org:** `schema:HowTo`
**CMMN:** `CasePlanModel`
_A workflow definition for a case type — defines process steps, status transitions, guards, and automatic actions._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Name of this workflow template |
| description | string | No | Purpose and usage notes |
| caseType | uuid (CASCADE delete) | Yes | Reference to the case type |
| version | integer (default: 1) | No | Auto-incrementing version number |
| isActive | boolean (default: false) | No | Whether this is the active version for new cases |
| isDraft | boolean (default: true) | No | Draft templates cannot be used for new cases |
| steps | string (JSON array) | No | WorkflowStep objects: {id, title, description, status (uuid), order, assigneeRole (uuid), isRequired, checklist, automaticActions} |
| transitions | string (JSON array) | No | StatusTransition objects: {id, fromStatus, toStatus, label, guards, automaticActions, allowedRoles}. Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify |
| nodePositions | string (JSON map) | No | Map of status UUID to {x, y} canvas positions for visual editor |
| parentWorkflow | uuid | No | Reference to parent workflow template for inheritance (Enterprise tier) |

**Relations:**
- → caseType (many-to-one, CASCADE delete)

---

### adviesAanvraag
**Schema.org:** `schema:AskAction`
_A request for internal or external advice on a case, with deadline tracking._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the case this advice is requested for |
| adviseur | string | Yes | User UID (internal) or organization name (external) |
| type | enum | Yes | intern / extern |
| onderwerp | string | No | Subject/topic of the advice request |
| deadline | date | No | Deadline for receiving the advice |
| status | enum (default: aangevraagd) | No | aangevraagd / ontvangen / verlopen |
| adviesDocument | string | No | Nextcloud file ID of the advice document |
| requestedAt | date-time | No | Timestamp when the advice was requested |
| receivedAt | date-time | No | Timestamp when the advice was received |
| questions | string | No | Specific questions for the adviseur |

**Relations:**
- → case (many-to-one)

---

### handhavingsactie
**Schema.org:** `schema:LegalForceStatus`
_An enforcement action (handhavingsactie) classified per Landelijke Handhavingsstrategie (LHS)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the handhavingszaak |
| type | enum | Yes | waarschuwing / vooraankondiging / last_onder_dwangsom / bestuursdwang / proces_verbaal |
| ernst | enum | Yes | gering / aanzienlijk / ernstig (LHS ernst axis) |
| gedrag | enum | Yes | goedwillend / onverschillig / calculerend / crimineel (LHS gedrag axis) |
| interventie | string | No | Suggested intervention from LHS matrix |
| begunstigingstermijn | integer | No | Grace period in days before enforcement takes effect |
| dwangsomBedrag | number | No | Penalty amount per violation (EUR) |
| dwangsomMaximaal | number | No | Maximum total penalty amount (EUR) |
| effectueringsDatum | date | No | Date when enforcement action takes effect |
| status | enum (default: opgelegd) | No | opgelegd / verbeurd / geeffectueerd / ingetrokken |
| overrideReason | string | No | Documented reasoning if LHS suggestion was overridden |

**Relations:**
- → case (many-to-one)

---

### inspectieChecklist
**Schema.org:** `schema:HowTo`
_Configurable inspection checklist template linked to a case type, with versioning support._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this checklist (e.g. 'Bouwtoezicht fase 1 - Fundering') |
| caseType | uuid | Yes | Reference to the case type |
| version | integer (default: 1) | No | Version number (incremented on edit) |
| status | enum (default: draft) | No | draft / active / archived |
| items | array of objects | No | Ordered checklist items ({order, label, type, required, fotoRequired, options, helpText}) |

**Item types:** ja_nee_nvt / tekst / getal / foto / meerkeuze

**Relations:**
- → caseType (many-to-one)

---

### inspectieRapport
**Schema.org:** `schema:Report`
_A completed inspection report generated from a checklist, stored on the case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the case (toezichtzaak) |
| checklist | uuid | Yes | Reference to the inspectieChecklist used |
| inspector | string | Yes | User UID of the inspector |
| inspectionDate | date-time | Yes | Date and time of the inspection |
| location | string | No | GPS coordinates or address of the inspection location |
| result | enum | No | conform / niet_conform / deels_conform (auto-calculated from items) |
| failedItems | integer (default: 0) | No | Count of failed checklist items |
| items | array of objects | No | Completed checklist item results ({itemId, result, comment, measurement, photos}) |
| photos | array of string | No | All Nextcloud file IDs of photos taken during inspection |
| remarks | string | No | General remarks about the inspection |
| followUpRequired | boolean (default: false) | No | Whether follow-up action is required |

**Relations:**
- → case (many-to-one)
- → inspectieChecklist (many-to-one)

---

### mapLayer
_GIS map layer configuration for case maps._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Display name for the layer in the layer switcher |
| layerType | enum | Yes | tile / wms / wfs / geojson |
| url | uri | Yes | Service URL (tile template, WMS base URL, WFS endpoint, or GeoJSON URL) |
| layers | string | No | WMS/WFS layer name(s), comma-separated |
| format | string (default: image/png) | No | Image format for WMS |
| attribution | string | No | Attribution text for the layer |
| isDefault | boolean (default: false) | No | Whether to show this layer on initial load |
| isBaseLayer | boolean (default: false) | No | If true, only one base layer visible at a time |
| opacity | number 0.0–1.0 (default: 1.0) | No | Layer opacity |
| minZoom | integer | No | Minimum zoom level for visibility |
| maxZoom | integer | No | Maximum zoom level for visibility |
| order | integer (default: 0) | No | Display order in the layer switcher |
| style | string (JSON object) | No | Style for GeoJSON/WFS features (color, weight, fillColor, fillOpacity) |
| proxyEnabled | boolean (default: false) | No | Whether to route requests through the backend GIS proxy |

---

## Entity Count Summary

| Group | Count | Schemas |
|-------|-------|---------|
| Type Definitions | 7 | caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType |
| Instance Entities | 7 | case, task, role, result, statusRecord, decision, document |
| Link Entities | 6 | documentLink, caseDocument, caseObject, caseProperty, decisionDocument, zaaktypeInformatieobjecttype |
| ZGW / Notification | 6 | catalogus, kanaal, abonnement, customerContact, dispatch, usageRights |
| Voorstel / Parafering | 3 | voorstel, parafeerroute, parafeeractie |
| Bezwaar / Beroep | 4 | objection, hearingSession, advisoryReport, appealDecision |
| VTH / Enforcement | 6 | workflowTemplate, adviesAanvraag, handhavingsactie, inspectieChecklist, inspectieRapport, mapLayer |
| **Total** | **39** | |

## ZGW Coverage

| ZGW Entity | Procest Entity | Notes |
|------------|----------------|-------|
| ZaakType | caseType | + draft lifecycle, CMMN alignment |
| StatusType | statusType | Direct mapping |
| ResultaatType | resultType | + archival rules |
| RolType | roleType | Direct mapping |
| Eigenschap | propertyDefinition | Direct mapping |
| InformatieObjectType | documentType | + isRequired, allowedMimeTypes |
| BesluitType | decisionType | Direct mapping |
| Zaak | case | + priority, workflowTemplate, CMMN status |
| Status | statusRecord | Direct mapping |
| Resultaat | result | Direct mapping |
| Rol | role | Direct mapping |
| Besluit | decision | Direct mapping |
| EnkelvoudigInformatieObject | document | + locked, fileParts, usageRightsIndication |
| ObjectInformatieObject | documentLink | Direct mapping |
| ZaakInformatieObject | caseDocument | Direct mapping |
| ZaakObject | caseObject | Direct mapping |
| ZaakEigenschap | caseProperty | Direct mapping |
| BesluitInformatieObject | decisionDocument | Direct mapping |
| Catalogus | catalogus | Direct mapping |
| Kanaal | kanaal | Direct mapping |
| Abonnement | abonnement | Direct mapping |
| KlantContact | customerContact | Direct mapping |
| Verzending | dispatch | Direct mapping |
| GebruiksRechten | usageRights | Direct mapping |
| ZaakTypeInformatieObjectType | zaaktypeInformatieobjecttype | Direct mapping |
| Bezwaarschrift | objection | + timeliness assessment |
| Hoorzitting | hearingSession | + video hearing support |
| AdviesBezwaarschriftencommissie | advisoryReport | Direct mapping |
| BeslissingOpBezwaar | appealDecision | + replacement decision link |

## Consequences

- All Procest data is stored in OpenRegister — Procest owns no database tables
- Schema changes require updating `lib/Settings/procest_register.json` and running the repair/import step
- The thin-client architecture means all CRUD operations go through the OpenRegister API from the Vue frontend
- ZGW compatibility is achieved through field-level mapping in API controllers, not by structuring storage around ZGW fields
- CMMN task lifecycle states (available, active, completed, terminated, disabled) are used for tasks to maintain standards alignment
- The parafering workflow is internal to Procest and has no direct ZGW equivalent
- Bezwaar/beroep entities implement the Awb (Algemene wet bestuursrecht) legal framework — timeliness check references art. 6:7, hearing per art. 7:2, advisory report per art. 7:13, appeal decision per art. 7:11-7:12
- VTH enforcement uses the Landelijke Handhavingsstrategie (LHS) matrix (ernst × gedrag axes)
- mapLayer is a configuration-only entity — it stores GIS layer definitions, not case data


## App Architecture ADRs from Repo (1 files)

These ADR files live in procest/openspec/architecture/.

### ADR-000-data-model
# ADR-000: Data Model — Procest

**Status:** accepted
**Standard:** CMMN 1.1 (OMG) + Schema.org + ZGW API mapping
**Storage:** OpenRegister (JSON object store, no own tables)
**Entities:** 39 schemas across 7 groups

## Context

Procest is a case management (zaakgericht werken) app for Nextcloud. It follows the
**thin-client pattern**: the app owns no database tables. All data is stored as
OpenRegister objects, validated against schemas defined in `lib/Settings/procest_register.json`.

The data model uses a layered standards approach:

| Layer | Standard | Purpose |
|-------|----------|---------|
| Primary (storage) | CMMN 1.1 concepts + Schema.org vocabulary | International case model |
| Semantic | Schema.org JSON-LD | Type annotations for linked data |
| API mapping | ZGW/RGBZ field names | Dutch government interoperability |
| Type system reference | ZGW Catalogi API (ZaakType) | Case type behavioral controls |
| Nextcloud native | Calendar, Files, Activity, Talk | Reuse where possible |

**Design principle:** International standards first. ZGW/RGBZ is an API mapping layer,
not the storage model. This makes Procest usable outside the Netherlands while remaining
interoperable with Dutch government systems.

OpenRegister built-in fields (NOT listed in tables below, always available on every entity):
`id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`,
`register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`,
`status`, `locked`.

## Decision

### Entity Groups

1. **Type Definitions** — Catalogue-level blueprints: caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType
2. **Instance Entities** — Runtime case data: case, task, role, result, statusRecord, decision, document
3. **Link Entities** — Relation join tables: documentLink, caseDocument, caseObject, caseProperty, decisionDocument, zaaktypeInformatieobjecttype
4. **ZGW / Notification** — Catalogus, kanaal, abonnement, customerContact, dispatch, usageRights
5. **Voorstel / Parafering** — Internal approval workflow: voorstel, parafeerroute, parafeeractie
6. **Bezwaar / Beroep** — Objection lifecycle: objection, hearingSession, advisoryReport, appealDecision
7. **VTH / Enforcement** — Inspection and enforcement: adviesAanvraag, handhavingsactie, inspectieChecklist, inspectieRapport, mapLayer, workflowTemplate

---

## Group 1: Type Definitions

### caseType
**CMMN:** `CaseDefinition` / `CasePlanModel` template
**Schema.org:** `schema:Project`
**ZGW:** `ZaakType` (Catalogi API 1.3.x)
_Blueprint for a category of cases — controls lifecycle, deadlines, confidentiality, and archival._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Name of this case type (translatable) |
| description | string | No | Detailed description (translatable) |
| identifier | string | No | Auto-generated identifier |
| catalogus | uuid | No | Reference to parent catalogus |
| purpose | string | No | Goal of this case type (translatable) |
| trigger | string | No | What triggers creation of a case (translatable) |
| subject | string | No | Subject matter (translatable) |
| processingDeadline | string (ISO 8601 duration) | No | Processing deadline (e.g. P30D) |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| isDraft | boolean (default: true) | No | Whether this case type is a draft |
| validFrom | date | No | Date from which this case type is valid |
| validUntil | date | No | Date until which it is valid (null = indefinite) |
| origin | string | No | Initiator action (e.g. indienen, aanvragen) |
| suspensionAllowed | boolean (default: false) | No | Whether cases can be suspended |
| extensionAllowed | boolean (default: false) | No | Whether deadline can be extended |
| extensionPeriod | string (ISO 8601 duration) | No | Extension period duration |
| publicationRequired | boolean (default: false) | No | Whether publication of decision is required |
| internalOrExternal | enum | No | intern / extern |
| handlerAction | string | No | Action performed by the handler |
| productsOrServices | string (JSON array) | No | URLs to products or services |
| selectionListProcessType | uri | No | URL to selectielijst process type |
| referenceProcess | string (JSON object) | No | Reference process definition |
| responsible | string | No | Responsible person or department |
| relatedCaseTypes | string (JSON array) | No | Related case types |
| subCaseTypes | array of string | No | References to sub-case types (deelzaaktypen) |
| decisionTypes | array of string | No | References to linked decision types (besluittypen) |

**Relations:**
- → statusType (one-to-many): lifecycle phases
- → resultType (one-to-many): possible outcomes
- → roleType (one-to-many): participant role definitions
- → propertyDefinition (one-to-many): custom field definitions
- → documentType (one-to-many): required documents
- → decisionType (many-to-many): linked decision types
- → workflowTemplate (one-to-many): workflow processes

---

### statusType
**Schema.org:** `schema:ActionStatusType`
**ZGW:** `StatusType`
_Lifecycle phase definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this status (e.g. Ontvangen, In behandeling) (translatable) |
| description | string | No | Description of this status phase (translatable) |
| caseType | uuid | Yes | Reference to the parent case type |
| order | integer (default: 0) | Yes | Position in the lifecycle (lower = earlier) |
| isFinal | boolean (default: false) | No | Whether this is a terminal/final status |

**Relations:**
- → caseType (many-to-one)

---

### resultType
**Schema.org:** `schema:Thing`
**ZGW:** `ResultaatType`
_Case outcome type definition with archival retention rules._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this result type (e.g. Vergunning verleend) (translatable) |
| description | string | No | Description/toelichting (translatable) |
| genericDescription | string | No | Generic description from selectielijst resultaattypeomschrijving |
| caseType | uuid | Yes | Reference to the parent case type |
| archivalPeriod | string (ISO 8601 duration) | No | Archival retention period |
| archivalAction | enum | No | bewaren / vernietigen / blijvend_bewaren |
| sourceDateArchiveProcedure | string (JSON object) | No | BrondatumArchiefprocedure config (afleidingswijze, procestermijn, datumkenmerk) |
| selectionListClass | uri | No | URL to selectielijstklasse |

**Relations:**
- → caseType (many-to-one)

---

### roleType
**Schema.org:** `schema:Role`
**ZGW:** `RolType`
_Participant role type definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the role (e.g. Behandelaar, Adviseur) (translatable) |
| description | string | No | Description of this role type (translatable) |
| caseType | uuid | Yes | Reference to the parent case type |

**Relations:**
- → caseType (many-to-one)

---

### propertyDefinition
**Schema.org:** `schema:PropertyValueSpecification`
**ZGW:** `Eigenschap`
_Custom field definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the custom property |
| definition | string | No | Short definition |
| description | string | No | Longer explanation |
| caseType | uuid | Yes | Reference to the parent case type |
| propertyType | enum | No | string / number / boolean / date / url / email |
| isRequired | boolean (default: false) | No | Whether required on cases |
| defaultValue | string | No | Default value |

**Relations:**
- → caseType (many-to-one)

---

### documentType
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `InformatieObjectType`
_Document type requirement definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of the document type (translatable) |
| description | string | No | Description (translatable) |
| catalogus | uuid | No | Reference to parent catalogus |
| caseType | uuid | No | Reference to the parent case type |
| isDraft | boolean (default: true) | No | Whether this is a draft (concept) |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| category | string | No | Document type category |
| isRequired | boolean (default: false) | No | Whether this document is required |
| allowedMimeTypes | string (JSON array) | No | Allowed MIME types |
| validFrom | date | No | Date from which valid |
| validUntil | date | No | Date until which valid |

**Relations:**
- → caseType (many-to-one)

---

### decisionType
**Schema.org:** `schema:ChooseAction`
**ZGW:** `BesluitType`
_Decision type definition for a case type._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this decision type (translatable) |
| description | string | No | Description (translatable) |
| catalogus | uuid | No | Reference to parent catalogus |
| caseType | uuid | No | Reference to the parent case type |
| isDraft | boolean (default: true) | No | Whether this is a draft |
| publicationRequired | boolean (default: false) | No | Whether publication is required |
| caseTypes | array of string | No | References to case types (zaaktype URLs) |
| documentTypes | array of string | No | References to document types (informatieobjecttype URLs) |
| validFrom | date | No | Date from which valid |
| validUntil | date | No | Date until which valid |

**Relations:**
- → caseType (many-to-one)

---

## Group 2: Instance Entities

### case
**CMMN:** `CaseInstance` (runtime)
**Schema.org:** `schema:Project`
**ZGW:** `Zaak`
_A case instance in the case management system._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Title of this case |
| description | string | No | Detailed description |
| identifier | string | No | Auto-generated case identifier (e.g. 2026-0042) |
| caseType | uuid (facetable) | Yes | Reference to the case type |
| status | uuid (facetable) | No | Reference to the current status type |
| result | uuid | No | Reference to the result record (set on completion) |
| startDate | date | No | Date the case was started |
| endDate | date | No | Date the case was completed |
| plannedEndDate | date | No | Planned end date |
| deadline | date | No | Processing deadline |
| confidentiality | enum (facetable) | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| assignee | string (facetable) | No | Nextcloud user ID of the primary handler |
| priority | enum (facetable, default: normal) | No | low / normal / high / urgent |
| parentCase | uuid | No | Reference to parent case (for sub-cases / deelzaken) |
| relatedCases | string (JSON array) | No | References to related cases |
| geometry | string (JSON object) | No | GeoJSON geometry for location-based cases |
| statusHistory | string (JSON array) | No | History of status changes |
| activity | string (JSON array) | No | Activity log entries |
| extensionCount | integer (default: 0) | No | Number of deadline extensions applied |
| sourceOrganisation | string (max 9) | No | RSIN of the creating organization |
| archiveNomination | enum | No | blijvend_bewaren / vernietigen |
| archiveActionDate | date | No | Date when archive action executes |
| archiveStatus | enum | No | nog_te_archiveren / gearchiveerd / gearchiveerd_procestermijn_onbekend / overgedragen |
| paymentIndication | enum | No | nvt / nog_niet / gedeeltelijk / geheel |
| lastPaymentDate | date | No | Date of last payment |
| communicationChannel | uri | No | URL reference to communication channel |
| workflowTemplate | uuid | No | Reference to the bound workflow template |
| workflowVersion | integer | No | Version number of the bound workflow template |

**Relations:**
- → caseType (many-to-one)
- → statusType (many-to-one, current status)
- → task (one-to-many, CASCADE delete)
- → role (one-to-many, CASCADE delete)
- → result (one-to-one, CASCADE delete)
- → statusRecord (one-to-many, CASCADE delete)
- → decision (one-to-many, CASCADE delete)
- → caseDocument (one-to-many, CASCADE delete)
- → caseObject (one-to-many, CASCADE delete)
- → caseProperty (one-to-many, CASCADE delete)
- → customerContact (one-to-many, CASCADE delete)
- → voorstel (one-to-many, CASCADE delete)

---

### task
**CMMN:** `HumanTask`
**Schema.org:** `schema:Action`
**ZGW:** `Taak`
_A task within a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Title of this task |
| description | string | No | Detailed description |
| status | enum (facetable, default: available) | No | available / active / completed / terminated / disabled (CMMN HumanTask lifecycle) |
| case | uuid (CASCADE delete) | Yes | Reference to the parent case |
| assignee | string (facetable) | No | Nextcloud user ID of the assigned user |
| dueDate | date-time | No | Due date for this task |
| priority | enum (facetable, default: normal) | No | low / normal / high / urgent |
| completedDate | date-time | No | Date the task was completed |
| workflowStepId | string | No | UUID of the workflow step that generated this task |
| checklist | string (JSON array) | No | Checklist items ({id, label, checked}) |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### role
**Schema.org:** `schema:Role`
**ZGW:** `Rol`
_A role assignment on a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Display name for this role assignment |
| roleType | uuid | Yes | Reference to the role type |
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| participant | string | Yes | Nextcloud user ID or contact reference |
| description | string | No | Description of this role assignment |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → roleType (many-to-one)

---

### result
**Schema.org:** `schema:Thing`
**ZGW:** `Resultaat`
_A case outcome record._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | No | Name of this result |
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| resultType | uuid | Yes | Reference to the result type |
| description | string | No | Description of this result |

**Relations:**
- → case (one-to-one, CASCADE delete)
- → resultType (many-to-one)

---

### statusRecord
**Schema.org:** `schema:Event`
**ZGW:** `Status`
_A status transition record for a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| statusType | uuid | Yes | Reference to the status type |
| description | string | No | Status transition description |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → statusType (many-to-one)

---

### decision
**Schema.org:** `schema:ChooseAction`
**ZGW:** `Besluit`
_A formal decision on a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | No | Title of this decision |
| case | uuid (CASCADE delete) | No | Reference to the case |
| description | string | No | Description of this decision |
| decisionType | uuid | No | Reference to the decision type |
| responsibleOrganisation | string | No | RSIN of the responsible organisation |
| decisionDate | date | No | Date the decision was made |
| effectiveDate | date | No | Date the decision takes effect |
| expiryDate | date | No | Date the decision expires |
| publicationDate | date | No | Publication date |
| deliveryDate | date | No | Delivery date |
| explanation | string | No | Explanation of the decision |
| governingBody | string | No | Governing body (bestuursorgaan) that made the decision |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decisionType (many-to-one)
- → decisionDocument (one-to-many, CASCADE delete)
- → objection (one-to-many, via contestedDecision)
- → appealDecision (one-to-many, via contestedDecision)

---

### document
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `EnkelvoudigInformatieObject`
_A document (enkelvoudig informatieobject) in the document registry._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| identifier | string | No | Auto-generated document identifier |
| sourceOrganisation | string | No | RSIN of the source organisation |
| creationDate | date | No | Date the document was created |
| title | string (max 255) | Yes | Title of this document |
| confidentiality | enum | No | openbaar / beperkt_openbaar / intern / zaakvertrouwelijk / vertrouwelijk / confidentieel / geheim / zeer_geheim |
| author | string | No | Author of the document |
| status | enum | No | in_bewerking / ter_vaststelling / definitief / gearchiveerd |
| format | string | No | MIME type (e.g. application/pdf) |
| language | string (default: nld) | No | ISO 639-2/B language code |
| fileName | string | No | Original file name |
| fileSize | integer | No | File size in bytes |
| content | string | No | Base64-encoded file content or file reference |
| link | uri | No | URL to the document |
| description | string | No | Description of the document |
| documentType | uuid | No | Reference to the document type |
| locked | boolean (default: false) | No | Whether the document is locked for editing |
| lockId | string | No | Identifier of the current lock |
| fileParts | string (JSON array) | No | References to file parts for chunked uploads |
| usageRightsIndication | boolean (nullable) | No | Whether usage rights have been set |

**Relations:**
- → documentType (many-to-one)
- → caseDocument (one-to-many, via case linking)
- → decisionDocument (one-to-many, via decision linking)

---

## Group 3: Link Entities

### documentLink
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `ObjectInformatieObject`
_A link between a document and a case or decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| object | uri | Yes | URI reference to the related object (zaak or besluit) |
| objectType | enum | Yes | zaak / besluit |

---

### caseDocument
**ZGW:** `ZaakInformatieObject`
_Links a document to a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| document | uri | Yes | URI reference to the document |
| title | string | No | Title/description of the relation |
| description | string | No | Description of the relation |
| registrationDate | date | No | Registration date |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### caseObject
**ZGW:** `ZaakObject`
_Links an external object to a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| objectUrl | uri | No | URL of the external object |
| objectType | string | Yes | Type of the external object |
| objectIdentification | string | No | JSON identification of the object |
| description | string | No | Description of the relation |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### caseProperty
**ZGW:** `ZaakEigenschap`
_A custom property value on a specific case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| propertyDefinition | uuid | Yes | Reference to the property definition (eigenschap) |
| value | string | Yes | The property value |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → propertyDefinition (many-to-one)

---

### decisionDocument
**ZGW:** `BesluitInformatieObject`
_Links a document to a decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| decision | uuid (CASCADE delete) | Yes | Reference to the decision |
| document | uri | Yes | URI reference to the document |

**Relations:**
- → decision (many-to-one, CASCADE delete)

---

### zaaktypeInformatieobjecttype
**ZGW:** `ZaakTypeInformatieObjectType`
_Links a case type to a document type with direction and ordering._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | uuid | Yes | Reference to the case type |
| informatieobjecttype | uuid | Yes | Reference to the document type |
| volgnummer | integer | Yes | Ordering number |
| richting | enum | Yes | inkomend / intern / uitgaand |
| statustype | uuid | No | Reference to a status type |

---

## Group 4: ZGW / Notification Entities

### catalogus
**Schema.org:** `schema:DataCatalog`
**ZGW:** `Catalogus`
_Groups case types, decision types, and document types._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| domein | string (max 5) | Yes | Abbreviated domain name |
| rsin | string (max 9) | No | RSIN of the responsible organisation |
| contactpersoonBeheerNaam | string (max 40) | No | Name of the management contact |
| contactpersoonBeheerTelefoonnummer | string (max 20) | No | Phone number of the management contact |
| contactpersoonBeheerEmailadres | string (max 254) | No | Email of the management contact |

---

### kanaal
**Schema.org:** `schema:BroadcastChannel`
**ZGW:** `Kanaal`
_A notification channel for ZGW event distribution._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| naam | string (max 50) | Yes | Name of this channel (e.g. zaken, documenten) |
| documentatieLink | uri | No | URL to API documentation for this channel |
| filters | string (JSON array) | No | Available filter attributes for this channel |

---

### abonnement
**Schema.org:** `schema:SubscribeAction`
**ZGW:** `Abonnement`
_A subscription for receiving ZGW notifications._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| callbackUrl | uri | Yes | URL to POST notifications to |
| auth | string | Yes | Authorization header value for callback requests |
| kanalen | string (JSON array) | Yes | Channels and filters to subscribe to |

---

### customerContact
**ZGW:** `KlantContact`
_A customer contact moment for a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the case |
| contactDateTime | date-time | No | Date-time of the contact |
| channel | string | No | Communication channel |
| subject | string | No | Subject of the contact |
| initiator | string | No | Who initiated the contact |

**Relations:**
- → case (many-to-one, CASCADE delete)

---

### dispatch
**ZGW:** `Verzending`
_A document dispatch record (verzending)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document |
| involvedParty | uri | No | URI of the involved party |
| relationshipType | string | Yes | Type of relationship (afzender/geadresseerde) |
| description | string | No | Description of the dispatch |
| receiveDate | date | No | Date received |
| sendDate | date | No | Date sent |
| contactPerson | uri | No | Contact person URI |
| contactPersonName | string | No | Name of the contact person |

---

### usageRights
**Schema.org:** `schema:DigitalDocument`
**ZGW:** `GebruiksRechten`
_Usage rights (gebruiksrechten) for a document._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| document | uri | Yes | URI reference to the document (EnkelvoudigInformatieObject) |
| startDate | string | Yes | Start date of the usage rights |
| endDate | string | No | End date of the usage rights |
| conditionsDescription | string | Yes | Description of the usage conditions |

---

## Group 5: Voorstel / Parafering

### voorstel
**Schema.org:** `schema:CreativeWork`
_A B&W voorstel (proposal) for decision-making in a case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | Reference to the parent case |
| type | enum (facetable) | Yes | dt_advies / collegeadvies / raadsvoorstel |
| onderwerp | string (max 255) | Yes | Subject (usually derived from case title) |
| steller | string (facetable) | Yes | Nextcloud user UID who created the voorstel |
| afdeling | string | No | Department of the steller |
| portefeuillehouder | string | No | Nextcloud user UID of the portfolio holder (wethouder) |
| status | enum (facetable, default: concept) | Yes | concept / in_parafering / ter_accordering / geaccordeerd / aangeboden / besloten / gearchiveerd / teruggestuurd |
| parafeerroute | uuid | No | Reference to the parafeerroute being used |
| routeSnapshot | string (JSON array) | No | Snapshot of parafeerroute steps at submission time |
| currentStep | integer (default: 0) | No | Current step in the parafeerroute (1-based, 0 = not yet submitted) |
| returnedFromStep | integer | No | Step from which the voorstel was returned |
| document | string | No | Nextcloud file ID of the primary voorstel document |
| bijlagen | array of string | No | Nextcloud file IDs of attached documents |
| behandeling | enum | No | hamerstuk / bespreekstuk |
| decision | uuid | No | Reference to the linked decision (set when besluit is registered) |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → parafeerroute (many-to-one)
- → parafeeractie (one-to-many, CASCADE delete)

---

### parafeerroute
**Schema.org:** `schema:HowTo`
_A configurable endorsement route defining the sequence of parafering steps for a voorstel._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this route (e.g. Collegeadvies - Omgevingsvergunning) |
| caseType | uuid (facetable) | No | Reference to the case type this route is associated with |
| voorstelType | enum | No | dt_advies / collegeadvies / raadsvoorstel |
| steps | array of objects | Yes | Ordered list of parafering steps ({order, type, actor, actorType, mandatory, label}) |
| isDefault | boolean (default: false) | No | Whether this is the default route for the linked case type and voorstel type |
| description | string | No | Description of when this route should be used |

**Step types:** advies / parafering / accordering
**Actor types:** user / group / role

---

### parafeeractie
**Schema.org:** `schema:Action`
_An immutable record of a parafering action on a voorstel step._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| voorstel | uuid (CASCADE delete) | Yes | Reference to the voorstel |
| step | integer | Yes | Step number in the parafeerroute |
| actor | string | Yes | Nextcloud user UID who performed the action |
| actorType | enum (default: user) | No | user / delegate |
| onBehalfOf | string | No | Nextcloud user UID of the principal (if acting as delegate) |
| action | enum (facetable) | Yes | parafered / returned / advised / skipped |
| comment | string | No | Comment or reason (mandatory for returned/skipped) |
| advice | string | No | Advisory text (for advies steps) |
| mandate | string | No | Mandate reference (for delegate actions) |

**Relations:**
- → voorstel (many-to-one, CASCADE delete)

---

## Group 6: Bezwaar / Beroep

### objection
**Schema.org:** `schema:Message`
**ZGW:** `Bezwaarschrift`
_Bezwaarschrift (objection letter) — formal objection content linked to a bezwaar case and the contested decision._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this objection belongs to |
| contestedDecision | uuid | Yes | The original besluit being contested |
| grounds | string | Yes | Grounds for objection (gronden van bezwaar) |
| requestedRelief | string | No | What outcome the bezwaarmaker seeks |
| receivedDate | date | Yes | Date the bezwaarschrift was received |
| receivedChannel | enum | Yes | brief / email / formulier / balie |
| isTimely | boolean | No | Whether objection was filed within the 6-week term (Awb art. 6:7) |
| timelinessAssessment | string | No | Explanation of timeliness determination |
| proVoorziening | boolean (default: false) | No | Whether voorlopige voorziening (interim relief) was requested |
| attachments | string (JSON array) | No | Document references uploaded by bezwaarmaker |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decision (many-to-one, via contestedDecision)

---

### hearingSession
**Schema.org:** `schema:Event`
**ZGW:** `Hoorzitting`
_Manages scheduling, invitations, and minutes for bezwaar hearings per Awb art. 7:2._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this hearing belongs to |
| scheduledDate | date-time | Yes | Date and time of the hearing |
| location | string | No | Physical location or 'Online' for video hearings |
| videoCallUrl | uri | No | Video conference link for online hearings |
| chairperson | uuid (→ role) | Yes | Who chairs the hearing (voorzitter) |
| members | string (JSON array) | No | Committee member role UUIDs |
| invitees | string (JSON array) | Yes | Invitee objects (name, role, email, status) |
| minutesSummary | string | No | Summary of what was discussed (verslag) |
| minutesDocument | uuid | No | Reference to full hearing minutes document |
| status | enum (default: gepland) | Yes | gepland / uitgenodigd / uitgevoerd / geannuleerd / afgezien |
| hearingWaived | boolean (default: false) | No | Bezwaarmaker has waived the right to be heard |
| waiverReason | string | No | Reason for waiving hearing right |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → role (many-to-one, via chairperson)

---

### advisoryReport
**Schema.org:** `schema:Report`
**ZGW:** `AdviesBezwaarschriftencommissie`
_Advisory committee report per Awb art. 7:13._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case this report belongs to |
| hearingSession | uuid (→ hearingSession) | No | The hearing session this report is based on |
| committeeChair | uuid (→ role) | Yes | Voorzitter who signed the report |
| committeeMembers | string (JSON array) | No | Committee member role UUIDs |
| adviceDate | date | Yes | Date the advice was issued |
| adviceType | enum | Yes | gegrond / ongegrond / deels_gegrond / niet_ontvankelijk |
| summary | string | Yes | Summary of the committee's advice |
| grounds | string | Yes | Legal reasoning and grounds for the advice |
| recommendation | string | Yes | Recommended action for the bestuursorgaan |
| deviationFromPrimaryDecision | boolean | Yes | Whether committee advises differently from original decision |
| reportDocument | uuid | No | Reference to full advisory report document |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → hearingSession (many-to-one)
- → role (many-to-one, via committeeChair)

---

### appealDecision
**Schema.org:** `schema:LegalForceStatus`
**ZGW:** `BeslissingOpBezwaar`
_Beslissing op bezwaar — formal decision recording with disposition and rechtsmiddelenclausule per Awb art. 7:11-7:12._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid (CASCADE delete) | Yes | The bezwaar case |
| contestedDecision | uuid (→ decision) | Yes | The original besluit being contested |
| advisoryReport | uuid (→ advisoryReport) | No | The committee's advisory report |
| dispositionType | enum | Yes | gegrond / ongegrond / deels_gegrond / niet_ontvankelijk |
| dispositionDetails | string | Yes | Detailed motivation (motiveringsplicht art. 7:12) |
| followsAdvice | boolean | No | Whether the decision follows the committee's advice |
| deviationReason | string | No | Reason for deviating from committee advice |
| remedialAction | string | No | Corrective action if gegrond/deels_gegrond |
| replacementDecision | uuid (→ decision) | No | New besluit that replaces the contested one |
| decisionDate | date | Yes | Date the decision was made |
| effectiveDate | date | Yes | Date the decision takes legal effect |
| appealInformation | string | Yes | Beroep possibilities (rechtsmiddelenclausule) |
| decisionMaker | uuid (→ role) | Yes | The person/body that made the decision |
| decisionDocument | uuid | No | Reference to the formal decision letter document |

**Relations:**
- → case (many-to-one, CASCADE delete)
- → decision (many-to-one, via contestedDecision)
- → advisoryReport (many-to-one)
- → role (many-to-one, via decisionMaker)

---

## Group 7: VTH / Enforcement

### workflowTemplate
**Schema.org:** `schema:HowTo`
**CMMN:** `CasePlanModel`
_A workflow definition for a case type — defines process steps, status transitions, guards, and automatic actions._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Name of this workflow template |
| description | string | No | Purpose and usage notes |
| caseType | uuid (CASCADE delete) | Yes | Reference to the case type |
| version | integer (default: 1) | No | Auto-incrementing version number |
| isActive | boolean (default: false) | No | Whether this is the active version for new cases |
| isDraft | boolean (default: true) | No | Draft templates cannot be used for new cases |
| steps | string (JSON array) | No | WorkflowStep objects: {id, title, description, status (uuid), order, assigneeRole (uuid), isRequired, checklist, automaticActions} |
| transitions | string (JSON array) | No | StatusTransition objects: {id, fromStatus, toStatus, label, guards, automaticActions, allowedRoles}. Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify |
| nodePositions | string (JSON map) | No | Map of status UUID to {x, y} canvas positions for visual editor |
| parentWorkflow | uuid | No | Reference to parent workflow template for inheritance (Enterprise tier) |

**Relations:**
- → caseType (many-to-one, CASCADE delete)

---

### adviesAanvraag
**Schema.org:** `schema:AskAction`
_A request for internal or external advice on a case, with deadline tracking._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the case this advice is requested for |
| adviseur | string | Yes | User UID (internal) or organization name (external) |
| type | enum | Yes | intern / extern |
| onderwerp | string | No | Subject/topic of the advice request |
| deadline | date | No | Deadline for receiving the advice |
| status | enum (default: aangevraagd) | No | aangevraagd / ontvangen / verlopen |
| adviesDocument | string | No | Nextcloud file ID of the advice document |
| requestedAt | date-time | No | Timestamp when the advice was requested |
| receivedAt | date-time | No | Timestamp when the advice was received |
| questions | string | No | Specific questions for the adviseur |

**Relations:**
- → case (many-to-one)

---

### handhavingsactie
**Schema.org:** `schema:LegalForceStatus`
_An enforcement action (handhavingsactie) classified per Landelijke Handhavingsstrategie (LHS)._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the handhavingszaak |
| type | enum | Yes | waarschuwing / vooraankondiging / last_onder_dwangsom / bestuursdwang / proces_verbaal |
| ernst | enum | Yes | gering / aanzienlijk / ernstig (LHS ernst axis) |
| gedrag | enum | Yes | goedwillend / onverschillig / calculerend / crimineel (LHS gedrag axis) |
| interventie | string | No | Suggested intervention from LHS matrix |
| begunstigingstermijn | integer | No | Grace period in days before enforcement takes effect |
| dwangsomBedrag | number | No | Penalty amount per violation (EUR) |
| dwangsomMaximaal | number | No | Maximum total penalty amount (EUR) |
| effectueringsDatum | date | No | Date when enforcement action takes effect |
| status | enum (default: opgelegd) | No | opgelegd / verbeurd / geeffectueerd / ingetrokken |
| overrideReason | string | No | Documented reasoning if LHS suggestion was overridden |

**Relations:**
- → case (many-to-one)

---

### inspectieChecklist
**Schema.org:** `schema:HowTo`
_Configurable inspection checklist template linked to a case type, with versioning support._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string (max 255) | Yes | Name of this checklist (e.g. 'Bouwtoezicht fase 1 - Fundering') |
| caseType | uuid | Yes | Reference to the case type |
| version | integer (default: 1) | No | Version number (incremented on edit) |
| status | enum (default: draft) | No | draft / active / archived |
| items | array of objects | No | Ordered checklist items ({order, label, type, required, fotoRequired, options, helpText}) |

**Item types:** ja_nee_nvt / tekst / getal / foto / meerkeuze

**Relations:**
- → caseType (many-to-one)

---

### inspectieRapport
**Schema.org:** `schema:Report`
_A completed inspection report generated from a checklist, stored on the case._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | uuid | Yes | Reference to the case (toezichtzaak) |
| checklist | uuid | Yes | Reference to the inspectieChecklist used |
| inspector | string | Yes | User UID of the inspector |
| inspectionDate | date-time | Yes | Date and time of the inspection |
| location | string | No | GPS coordinates or address of the inspection location |
| result | enum | No | conform / niet_conform / deels_conform (auto-calculated from items) |
| failedItems | integer (default: 0) | No | Count of failed checklist items |
| items | array of objects | No | Completed checklist item results ({itemId, result, comment, measurement, photos}) |
| photos | array of string | No | All Nextcloud file IDs of photos taken during inspection |
| remarks | string | No | General remarks about the inspection |
| followUpRequired | boolean (default: false) | No | Whether follow-up action is required |

**Relations:**
- → case (many-to-one)
- → inspectieChecklist (many-to-one)

---

### mapLayer
_GIS map layer configuration for case maps._

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string (max 255) | Yes | Display name for the layer in the layer switcher |
| layerType | enum | Yes | tile / wms / wfs / geojson |
| url | uri | Yes | Service URL (tile template, WMS base URL, WFS endpoint, or GeoJSON URL) |
| layers | string | No | WMS/WFS layer name(s), comma-separated |
| format | string (default: image/png) | No | Image format for WMS |
| attribution | string | No | Attribution text for the layer |
| isDefault | boolean (default: false) | No | Whether to show this layer on initial load |
| isBaseLayer | boolean (default: false) | No | If true, only one base layer visible at a time |
| opacity | number 0.0–1.0 (default: 1.0) | No | Layer opacity |
| minZoom | integer | No | Minimum zoom level for visibility |
| maxZoom | integer | No | Maximum zoom level for visibility |
| order | integer (default: 0) | No | Display order in the layer switcher |
| style | string (JSON object) | No | Style for GeoJSON/WFS features (color, weight, fillColor, fillOpacity) |
| proxyEnabled | boolean (default: false) | No | Whether to route requests through the backend GIS proxy |

---

## Entity Count Summary

| Group | Count | Schemas |
|-------|-------|---------|
| Type Definitions | 7 | caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType |
| Instance Entities | 7 | case, task, role, result, statusRecord, decision, document |
| Link Entities | 6 | documentLink, caseDocument, caseObject, caseProperty, decisionDocument, zaaktypeInformatieobjecttype |
| ZGW / Notification | 6 | catalogus, kanaal, abonnement, customerContact, dispatch, usageRights |
| Voorstel / Parafering | 3 | voorstel, parafeerroute, parafeeractie |
| Bezwaar / Beroep | 4 | objection, hearingSession, advisoryReport, appealDecision |
| VTH / Enforcement | 6 | workflowTemplate, adviesAanvraag, handhavingsactie, inspectieChecklist, inspectieRapport, mapLayer |
| **Total** | **39** | |

## ZGW Coverage

| ZGW Entity | Procest Entity | Notes |
|------------|----------------|-------|
| ZaakType | caseType | + draft lifecycle, CMMN alignment |
| StatusType | statusType | Direct mapping |
| ResultaatType | resultType | + archival rules |
| RolType | roleType | Direct mapping |
| Eigenschap | propertyDefinition | Direct mapping |
| InformatieObjectType | documentType | + isRequired, allowedMimeTypes |
| BesluitType | decisionType | Direct mapping |
| Zaak | case | + priority, workflowTemplate, CMMN status |
| Status | statusRecord | Direct mapping |
| Resultaat | result | Direct mapping |
| Rol | role | Direct mapping |
| Besluit | decision | Direct mapping |
| EnkelvoudigInformatieObject | document | + locked, fileParts, usageRightsIndication |
| ObjectInformatieObject | documentLink | Direct mapping |
| ZaakInformatieObject | caseDocument | Direct mapping |
| ZaakObject | caseObject | Direct mapping |
| ZaakEigenschap | caseProperty | Direct mapping |
| BesluitInformatieObject | decisionDocument | Direct mapping |
| Catalogus | catalogus | Direct mapping |
| Kanaal | kanaal | Direct mapping |
| Abonnement | abonnement | Direct mapping |
| KlantContact | customerContact | Direct mapping |
| Verzending | dispatch | Direct mapping |
| GebruiksRechten | usageRights | Direct mapping |
| ZaakTypeInformatieObjectType | zaaktypeInformatieobjecttype | Direct mapping |
| Bezwaarschrift | objection | + timeliness assessment |
| Hoorzitting | hearingSession | + video hearing support |
| AdviesBezwaarschriftencommissie | advisoryReport | Direct mapping |
| BeslissingOpBezwaar | appealDecision | + replacement decision link |

## Consequences

- All Procest data is stored in OpenRegister — Procest owns no database tables
- Schema changes require updating `lib/Settings/procest_register.json` and running the repair/import step
- The thin-client architecture means all CRUD operations go through the OpenRegister API from the Vue frontend
- ZGW compatibility is achieved through field-level mapping in API controllers, not by structuring storage around ZGW fields
- CMMN task lifecycle states (available, active, completed, terminated, disabled) are used for tasks to maintain standards alignment
- The parafering workflow is internal to Procest and has no direct ZGW equivalent
- Bezwaar/beroep entities implement the Awb (Algemene wet bestuursrecht) legal framework — timeliness check references art. 6:7, hearing per art. 7:2, advisory report per art. 7:13, appeal decision per art. 7:11-7:12
- VTH enforcement uses the Landelijke Handhavingsstrategie (LHS) matrix (ernst × gedrag axes)
- mapLayer is a configuration-only entity — it stores GIS layer definitions, not case data
