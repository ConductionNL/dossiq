## Context

Procest is a thin-client Nextcloud app for case management. It stores all data as OpenRegister objects and renders UI via Vue 2.7 + Pinia. The workflow-engine-enhancement (PR #93) adds a status transition engine with guards and automatic actions to the case lifecycle.

B&W besluitvorming (Board & Aldermen decision-making) is the ambtelijk (civil servant) workflow for preparing, reviewing, and endorsing proposals before they reach the College for formal decision-making. This is a standard 10-step process in Dutch municipalities (GEMMA reference process).

**Current state:**
- Decision and DecisionType schemas exist in `procest_register.json`
- `BrcController.php` provides ZGW Besluiten API endpoints
- No voorstel, parafeerroute, or parafeeractie schemas exist
- No frontend components for B&W workflow exist
- Task management infrastructure provides a model for step-based workflows
- `NotificatieService.php` handles Nextcloud notifications

## Goals / Non-Goals

**Goals:**
- Implement V1 ambtelijk parafering: sequential step routing with audit trail
- Add 3 new schemas (voorstel, parafeerroute, parafeeractie) to the register
- Create voorstel lifecycle from case context through parafering to besluit registration
- Provide secretariaat dashboard and personal parafering inbox
- Ensure Archiefwet-compliant immutable audit trail
- Build on workflow-engine-enhancement status transitions and guards

**Non-Goals:**
- V2 parallel parafering (completionRule: all/any with multiple simultaneous actors)
- V2 mobile-specific parafering (tablet-optimized UI, offline queueing)
- V2 RIS connectors (iBabs/NotuBiz API integration via OpenConnector)
- Vergaderbeheer (meeting/agenda management -- handled by external RIS)
- Mandate/delegation configuration admin UI (V2; manual config in V1)

## Decisions

### D1: Voorstel as OpenRegister object (not task extension)

**Decision**: Model voorstellen as a dedicated OpenRegister schema rather than extending the existing task system.

**Rationale**: A voorstel has its own lifecycle (concept -> in_parafering -> geaccordeerd -> besloten -> gearchiveerd) distinct from task states. It carries document references, parafeerroute linkage, and case-type-specific metadata. Reusing tasks would overload the task model and blur the distinction between operational tasks and formal approval workflows.

**Alternative considered**: Model each parafering step as a task with type "parafering" -- rejected because the step sequence and route ownership semantics don't map cleanly to independent tasks.

### D2: Frontend-driven parafering engine

**Decision**: Parafering step advancement is driven by the frontend. When a parafeerder takes an action (paraferen/terugsturen/adviseren), the frontend updates the voorstel object (currentStep, status) and creates a parafeeractie record via two OpenRegister API calls.

**Rationale**: Procest is a thin-client app with no custom backend CRUD. OpenRegister provides the persistence layer. The workflow-engine-enhancement's status transition engine handles guard evaluation on the frontend side. This is consistent with the existing architecture.

**Alternative considered**: Backend-driven workflow via n8n -- rejected because it adds infrastructure complexity and the sequential logic is simple enough for frontend orchestration.

### D3: Parafeerroute stored as OpenRegister object with embedded steps array

**Decision**: The parafeerroute schema stores an ordered array of step objects (parafeerstap) as a JSON array property, not as separate OpenRegister objects.

**Rationale**: Steps are always loaded together with the route. Separate objects would require N+1 queries for each voorstel view. The steps array is bounded (typically 3-7 steps) and benefits from atomic route updates.

### D4: Parafeeractie as immutable append-only records

**Decision**: Each parafeeractie is a separate OpenRegister object. Once created, parafeeracties are never updated or deleted -- the audit trail is immutable by design.

**Rationale**: Legal requirement from Archiefwet: the full chain of endorsements must be reconstructable. OpenRegister objects are naturally append-friendly. Frontend code SHALL NOT expose update/delete operations on parafeeracties.

### D5: Besluit registration via existing BrcController and decision schema

**Decision**: When the college decides on a voorstel (step 9-10 of the process), the system creates a decision object using the existing `decision` schema and `BrcController` ZGW endpoints. No new besluit schema is needed.

**Rationale**: The decision schema and BrcController already implement the ZGW Besluiten API pattern. The voorstel's status transitions to "besloten" and links to the created decision UUID.

### D6: Vue router additions for parafering views

**Decision**: Add 3 new routes: `/voorstellen` (dashboard/list), `/voorstellen/:id` (detail), and extend `/settings` with a Parafeerroutes admin tab.

**Rationale**: Voorstellen need their own navigation entry for the secretariaat. The voorstel detail view is distinct from case detail. The personal inbox integrates into the existing MyWork view.

## Risks / Trade-offs

- **[Concurrency]** Two users acting on the same voorstel step simultaneously could create duplicate parafeeracties. Mitigation: Frontend checks currentStep matches expected step before submitting; OpenRegister's `_version` field enables optimistic locking.
- **[Performance]** Loading parafeerroute + all parafeeracties for each voorstel in a list view could be slow. Mitigation: Dashboard view loads voorstel summary only; detail view lazy-loads acties.
- **[No backend validation]** Without custom backend CRUD, schema validation relies on OpenRegister. Mitigation: Frontend validation before submission; OpenRegister schema constraints catch data integrity issues.
- **[PR #93 dependency]** This change requires the workflow-engine-enhancement to be merged first. Mitigation: Schema and component work can proceed independently; integration with guards/transitions is a final step.

## Open Questions

- Should the voorstel document be a Nextcloud Files reference (file ID) or an OpenRegister file attachment? Leaning toward Nextcloud Files ID for Docudesk integration compatibility.
- Should overdue parafering alerts use the signalering-widgets infrastructure or a dedicated parafering alert mechanism? Leaning toward reusing signalering pattern.
