## Context

Procest is a case management app for Nextcloud that stores all data in OpenRegister (no own database tables). The workflow-engine-enhancement (PR #93) added a configurable workflow engine with visual editor, guards (checklist, required-field, role, required-document), actions (6 types), and versioning. VTH (Vergunningen, Toezicht, Handhaving) is the highest-demand municipal domain (848 requirements across 236 tenders).

This change configures the workflow engine for VTH and adds domain-specific schemas, UI panels, and seed data. It does NOT add new core workflow engine functionality -- it builds on top of the existing engine.

**Current state**: No VTH-specific functionality exists. The case management infrastructure, ZGW API layer, case type admin UI, document management, deadline panel, and notification service are all in place. The workflow engine from PR #93 provides the workflowTemplate schema, visual editor, guard evaluation, and action dispatch.

## Goals / Non-Goals

**Goals:**
- Pre-built VTH workflow templates importable via the workflow engine
- VTH-specific OpenRegister schemas (inspectieChecklist, inspectieRapport, handhavingsactie, adviesAanvraag)
- VTH case type seed data (6 case types) loaded via repair step
- LHS matrix configuration UI in admin settings
- Inspection, enforcement, and advice panels on case dashboard
- Enforcement wizard for guided handhavingsactie creation

**Non-Goals:**
- DSO/Omgevingsloket integration (separate spec, requires OpenConnector)
- Mobile inspection UI (V2, separate spec)
- Supervision planning / toezichtplan dashboard (V2)
- Bekendmaking / publication workflow (V2, requires DROP/Gemeentelijk Publicatieplatform integration)
- Offline inspection support (V2)
- Legesberekening integration (separate spec)

## Decisions

### Decision 1: VTH workflow templates as JSON fixtures

**Choice**: Store VTH workflow templates as static JSON files under `lib/Settings/vth-templates/` and import them via the workflow engine's existing import functionality.

**Why**: The workflow engine already supports JSON import/export. Static fixtures are version-controlled, testable, and don't require a separate template registry. Administrators import templates via the existing WorkflowTab UI.

**Alternatives considered**:
- Dynamic template registry in OpenRegister: Adds complexity, requires template schema, harder to version control. Rejected.
- Embedded in procest_register.json: Register JSON is for schemas, not workflow definitions. Rejected.

### Decision 2: VTH schemas in procest_register.json

**Choice**: Add inspectieChecklist, checklistItem, inspectieRapport, handhavingsactie, and adviesAanvraag schemas to the existing `lib/Settings/procest_register.json`.

**Why**: Consistent with existing pattern. All Procest schemas live in one register file. ConfigurationService::importFromApp() handles the import in the repair step. OpenRegister provides CRUD API automatically.

**Alternatives considered**:
- Separate `vth_register.json`: Would require a second register, adding complexity. The procest register already has 25+ schemas. Rejected.

### Decision 3: LHS matrix stored in Nextcloud IAppConfig

**Choice**: Store the LHS matrix configuration in Nextcloud's IAppConfig as a JSON string (`procest/lhs_matrix`), not as an OpenRegister object.

**Why**: The LHS matrix is a global configuration setting, not a per-case data object. IAppConfig is the standard Nextcloud pattern for app settings. The SettingsController already reads/writes IAppConfig.

**Alternatives considered**:
- OpenRegister object: Over-engineered for a single configuration object. Would require a schema and CRUD UI. Rejected.

### Decision 4: Pinia stores for VTH domain logic

**Choice**: Create 3 new Pinia stores: `inspection.js`, `enforcement.js`, `advice.js` under `src/store/modules/`. Each store manages its domain's OpenRegister objects and UI state.

**Why**: Consistent with existing store pattern (workflow.js, case.js). Each VTH domain has distinct CRUD operations and UI state. Separation of concerns.

**Alternatives considered**:
- Single `vth.js` store: Too large, mixes unrelated concerns. Rejected.
- Options API with createObjectStore: The existing codebase uses Pinia stores for complex domain logic. VTH stores need computed properties and actions beyond basic CRUD. Consistent with workflow.js pattern.

### Decision 5: Dashboard panels as Vue components

**Choice**: Create InspectionPanel.vue, EnforcementPanel.vue, and AdvicePanel.vue as components under `src/views/cases/components/`. They are conditionally rendered on CaseDetail.vue based on the case type's VTH category.

**Why**: The CaseDetail view already renders domain-specific panels (DeadlinePanel, WorkflowTransitions). VTH panels follow the same pattern: fetch related objects, display in a collapsible panel.

**Detection logic**: Check case type properties or name pattern to determine VTH category:
- Toezicht case types -> show InspectionPanel
- Handhaving case types -> show EnforcementPanel
- All VTH case types -> show AdvicePanel (permits, supervision, and enforcement can all have advice)

### Decision 6: Seed data with idempotent upsert

**Choice**: VTH case type seed data is imported via the existing repair step using ConfigurationService. Each seed object has a stable identifier (slug) used for idempotent upsert -- skip if exists, create if not.

**Why**: Prevents duplicate case types on upgrade. Preserves user customizations. Consistent with existing register import pattern.

### Decision 7: Checklist admin in CaseType settings tabs

**Choice**: Add an "Inspectiechecklists" tab to CaseTypeDetail.vue for Toezicht case types. The tab provides CRUD for inspectieChecklist objects linked to the case type.

**Why**: Checklists are per-case-type configuration, not global settings. The CaseTypeDetail already has tabs (general, statuses, roles, documents, properties, workflow). Adding a checklist tab is consistent.

## Risks / Trade-offs

- **[PR #93 dependency]** This change builds on the workflow engine from PR #93 which is not yet merged. If PR #93 changes significantly, workflow templates may need updating. Mitigation: Templates use the workflowTemplate schema which is stable in PR #93.

- **[Seed data size]** 6 case types with full configuration (status types, role types, document types, property definitions, checklists) adds significant JSON to procest_register.json. Mitigation: Use references between objects to avoid duplication. Register import is a one-time operation.

- **[LHS matrix customization]** Municipalities may need more than 3 severity levels or 4 behavior types. Mitigation: V1 implements the standard 4x4 matrix. V2 can extend to custom dimensions if demanded.

- **[Checklist photo storage]** Photos are stored in Nextcloud Files under the case folder. Large inspection campaigns could generate significant storage. Mitigation: This is the standard Nextcloud pattern and benefits from existing storage management.

## Open Questions

- Should VTH workflow templates be bundled with the app (lib/Settings) or distributed as a separate "VTH template pack" that can be updated independently?
- Should the enforcement wizard generate actual documents (vooraankondigingsbrief) or just placeholder text until Docudesk integration is available?
