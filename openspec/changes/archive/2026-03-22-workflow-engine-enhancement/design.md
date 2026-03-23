## Context

Procest is a thin-client Nextcloud app for case management (zaakgericht werken). It stores all data in OpenRegister (JSON object storage) and renders a Vue 2.7 frontend that queries OpenRegister's API directly. The backend is minimal: SettingsController + ConfigurationService for register setup via repair steps.

Currently, case types have status types (ordered list), but there is no workflow engine connecting statuses with transitions, guards, process steps, or automatic actions. Administrators must manually configure each case type's behavior through individual CRUD screens. There is no way to define "when status X is reached, these steps must be completed before moving to status Y."

This change introduces a workflow engine layer on top of the existing case-types and task-management specs, enabling zero-code workflow configuration via a visual editor.

## Goals / Non-Goals

**Goals:**
- Introduce a `workflowTemplate` OpenRegister schema that defines process steps, status transitions, guards, and automatic actions per zaaktype
- Build a visual drag-and-drop workflow editor in the Vue 2.7 frontend (admin settings area)
- Implement a guard evaluation engine in the frontend that controls transition availability
- Support workflow versioning so running cases are not affected by workflow changes
- Enable workflow import/export for OTAP environment transfers
- Provide automatic action hooks (email, task creation, webhook, notifications)
- Support role-based filtering of steps and transitions

**Non-Goals:**
- Domain-specific workflow templates (VTH, Bezwaar/beroep, Besluitvorming) -- these are follow-up changes
- BPMN execution engine -- we use a simpler state-machine model, not a full BPMN runtime
- Backend-side guard evaluation -- guards are evaluated in the frontend; the backend stores the data
- Real-time multi-user editing of workflows -- standard last-write-wins applies
- GIS integration or signalering widgets

## Decisions

### D1: Workflow data stored as OpenRegister objects (not PHP entities)

**Decision**: Store workflow templates, steps, and transitions as OpenRegister JSON objects, consistent with Procest's thin-client architecture.

**Alternatives considered**:
- PHP Entity classes with own database tables: Would break the thin-client pattern. Procest owns no database tables.
- Separate workflow microservice: Over-engineering for the current scale.

**Rationale**: Procest's architecture stores everything in OpenRegister. The workflow template is configuration data that belongs with the zaaktype. Using OpenRegister means it gets schema validation, versioning support, and API access for free.

### D2: State-machine model (not BPMN runtime)

**Decision**: Use a simple state-machine model (statuses as nodes, transitions as edges with guards) rather than embedding a BPMN execution engine.

**Alternatives considered**:
- Embed Flowable/Camunda: Too heavy, Java dependency, doesn't fit Nextcloud app model.
- Use n8n as workflow engine: n8n is for integration workflows, not case lifecycle state machines.
- CMMN runtime: No mature PHP CMMN engine exists.

**Rationale**: The requirements are about configurable status transitions with guards and actions -- a state machine. BPMN/CMMN concepts (Stage, HumanTask, Sentry) inform the data model naming, but execution is a simple "check guards, update status, fire actions" loop in JavaScript.

### D3: Guard evaluation in frontend

**Decision**: Evaluate transition guards in the Vue frontend by checking case state against guard conditions.

**Alternatives considered**:
- Backend guard evaluation via PHP: Would require a new controller endpoint. Adds latency. Guards are simple field-presence and role checks.
- Hybrid (backend for security-critical guards): Could be added later for `customExpression` guards if needed.

**Rationale**: Guards check: (a) checklist completion status, (b) field presence, (c) document existence, (d) user role. All this data is already loaded in the frontend when viewing a case. No server roundtrip needed. Role guards use the already-loaded case roles.

### D4: Canvas library for visual editor

**Decision**: Use a lightweight Vue 2-compatible canvas/graph library for the drag-and-drop workflow editor. Candidates: vue-flow (if Vue 2 compatible), or custom SVG-based implementation using native drag events.

**Alternatives considered**:
- JointJS: Heavy, commercial license for advanced features.
- mxGraph: Discontinued, complex API.
- Raw HTML5 Canvas: No DOM events, accessibility concerns.

**Rationale**: The editor needs: draggable nodes, connectable ports, arrow rendering, and zoom/pan. A lightweight SVG approach with native HTML5 drag-and-drop keeps the bundle small and avoids heavy dependencies. If vue-flow supports Vue 2.7, prefer it; otherwise, build with SVG + D3.js for arrow path calculation.

### D5: Workflow versioning via copy-on-write

**Decision**: When editing a published workflow, create a new version (copy) as a draft. Published versions are immutable. Cases store a reference to their bound version.

**Alternatives considered**:
- In-place editing with migration: Dangerous for running cases, violates tender requirement #5.
- Git-style branching: Over-complex for the use case.

**Rationale**: Tender requirements explicitly state running cases must continue with the previous workflow version after changes. Copy-on-write is simple, safe, and lets administrators preview changes before publishing.

### D6: Automatic actions via frontend dispatch + n8n webhooks

**Decision**: Simple actions (notifications, field updates) execute in the frontend. Complex actions (email, sub-case creation) delegate to n8n webhooks.

**Alternatives considered**:
- All actions via PHP backend: Would require building an action execution engine in PHP.
- All actions via n8n: Simple field updates don't need n8n overhead.

**Rationale**: Email is being phased out for n8n (SMTP disabled on test env). Sub-case creation and complex integrations naturally fit n8n. Simple notifications use Nextcloud's OCS API from the frontend. This keeps Procest's backend minimal.

### D7: Register schema additions in procest_register.json

**Decision**: Add `workflowTemplate` schema to the existing `procest_register.json` (OpenAPI 3.0.0 format), imported via `ConfigurationService::importFromApp()` in the repair step.

**Rationale**: This is the established pattern for Procest data model extensions. The repair step ensures the schema exists when the app is installed or updated.

## Risks / Trade-offs

**[Frontend guard evaluation is not tamper-proof]** -- A technically savvy user could bypass frontend guards via direct API calls to OpenRegister. Mitigation: For V1 this is acceptable (administrators are trusted users). For Enterprise, add backend guard validation as a middleware.

**[Canvas library compatibility with Vue 2.7]** -- Vue 2 is nearing EOL, and most modern graph libraries target Vue 3. Mitigation: Use SVG + native drag APIs if no suitable Vue 2 library exists. Plan migration path when Nextcloud moves to Vue 3.

**[Workflow complexity scaling]** -- Very large workflows (50+ statuses) may cause performance issues in the visual editor. Mitigation: Set a soft limit of 30 status nodes with a warning. Optimize rendering with virtual scrolling if needed.

**[Import/export type matching]** -- Matching types by name between environments can fail if names differ. Mitigation: Include a manifest with type fingerprints and offer auto-create for missing types.

## Migration Plan

1. Add `workflowTemplate` schema to `procest_register.json`
2. Deploy via app update -- repair step auto-imports the new schema
3. Existing case types continue to work without workflows (backward compatible)
4. Administrators can optionally create workflow templates for existing case types
5. Cases created before workflow engine have no `workflowVersion` binding -- they use simple status lists as before
6. No data migration needed -- this is purely additive

**Rollback**: Remove the workflow template schema and related frontend components. Cases revert to simple status management. No data loss since workflows are separate objects.

## Open Questions

1. **Should guards support custom JSONPath expressions?** -- Deferred to V2. V1 supports only the defined guard types (checklist, requiredField, requiredDocument, roleGuard).
2. **Should workflow templates be shareable across zaaktypen?** -- Deferred. V1 has 1:1 template-to-zaaktype binding. Shared templates could be a V2 feature.
3. **How to handle n8n webhook authentication?** -- Use the existing n8n ExApp integration token. Details TBD during implementation.
