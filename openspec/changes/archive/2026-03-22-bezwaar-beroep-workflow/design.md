## Context

Procest is a Nextcloud-based case management app that stores all data as OpenRegister objects (no own database tables). The workflow engine enhancement (PR #93) added configurable workflow templates with visual editor, status transitions, guards, and automatic actions. This change builds on that foundation to add AWB-compliant bezwaar (objection) and beroep (appeal) processes as pre-seeded case types with workflow templates.

The bezwaar/beroep process is one of the most formalized procedures in Dutch administrative law (Awb chapters 6, 7, and 8). Every municipality must handle these, and the process has strict legal requirements for deadlines, hearing rights, advisory committees, and decision motivation. Market intelligence shows this appearing in 534+ tenders.

Current state:
- Workflow engine provides: workflow templates, steps, transitions, guards (4 types), automatic actions (6 types), visual editor, versioning
- Case type system provides: status types, role types, processing deadlines, extension/suspension support
- Decision system provides: formal decision recording with validity periods
- No bezwaar/beroep-specific schemas, components, or pre-seeded data exist yet

## Goals / Non-Goals

**Goals:**
- Pre-seed Bezwaar and Beroep case types with AWB-compliant configurations
- Pre-seed workflow templates with legally mandated process steps, transitions, and guards
- Add 4 new schemas (objection, hearingSession, advisoryReport, appealDecision) for bezwaar-specific entities
- Add dedicated Vue components for bezwaar/beroep case handling
- Add automatic deadline calculation based on AWB rules
- Enable escalation path from bezwaar to beroep with case linking

**Non-Goals:**
- Hoger beroep (further appeal to ABRvS/CRvB) workflow -- informational only
- Citizen-facing portal for filing bezwaar (that belongs to a separate "Mijn Zaken" feature)
- Automated document generation (bezwaar decision letters) -- documents are uploaded manually
- Integration with external court systems (Rechtspraak.nl)
- Pro-forma bezwaar handling (special case where grounds are submitted later)

## Decisions

### Decision 1: Bezwaar entities as separate schemas vs. case properties

**Choice**: Separate OpenRegister schemas for objection, hearingSession, advisoryReport, and appealDecision.

**Alternatives considered**:
- (A) Store everything as case properties -- simpler but loses structure and makes querying individual hearings/reports impossible
- (B) Use the existing decision schema for everything -- misses bezwaar-specific fields like `grounds`, `hearingWaived`, `adviceType`

**Rationale**: Bezwaar entities have distinct lifecycles and relationships. A hearing session can exist independently (scheduled, cancelled, waived). Advisory reports reference hearings. The appeal decision references both the advisory report and the original contested decision. Separate schemas enable clean CRUD operations and proper referential integrity.

### Decision 2: Pre-seeded data via procest_register.json

**Choice**: Add bezwaar/beroep schemas, case types, status types, role types, and workflow templates to `lib/Settings/procest_register.json`. They are imported via the existing `ConfigurationService::importFromApp()` repair step.

**Alternatives considered**:
- (A) Separate JSON file for bezwaar config -- complicates the repair step, inconsistent with existing pattern
- (B) Admin UI for creating bezwaar types manually -- poor UX, error-prone, municipalities expect out-of-box support

**Rationale**: Follows the established pattern. The repair step already handles idempotent imports (checks for existing objects before creating). Adding to the existing register JSON keeps everything in one place.

### Decision 3: Deadline calculation in frontend store

**Choice**: Deadline calculation logic lives in the `bezwaar.js` Pinia store, computed when a bezwaar case is created or when suspension/extension is applied.

**Alternatives considered**:
- (A) Backend PHP service for deadline calculation -- would require a new controller endpoint, inconsistent with thin-client architecture
- (B) Automatic action in workflow engine -- too rigid, deadlines depend on dynamic factors (suspension, extension)

**Rationale**: Procest follows the thin-client pattern where the frontend queries OpenRegister directly. Deadline calculation is deterministic (based on dates and AWB rules) and can be computed client-side. The store saves the computed deadlines to the case object properties.

### Decision 4: Hearing session as linked object, not embedded in case

**Choice**: HearingSession is a separate schema with a `case` reference, not an embedded property of the case.

**Rationale**: A case may have multiple hearing attempts (rescheduled, cancelled). The hearing has its own status lifecycle (gepland, uitgenodigd, uitgevoerd, geannuleerd, afgezien). Embedding would make it impossible to track hearing history.

### Decision 5: Vue components as case-type-aware tabs/panels

**Choice**: Add bezwaar-specific Vue components that render conditionally when the case's type is "Bezwaar" or "Beroep". These appear as additional sections in the case detail view.

**Alternatives considered**:
- (A) Completely separate views for bezwaar cases -- code duplication, loses shared case infrastructure
- (B) Generic component system with plugins -- over-engineered for 2 case types

**Rationale**: The case detail view already supports tabs/sections. Bezwaar-specific sections (objection details, hearing panel, advisory report, decision form) can be conditionally rendered based on case type. This keeps shared case functionality (timeline, documents, roles) while adding specialized panels.

### Decision 6: Beroep as lightweight tracking, not full court integration

**Choice**: Beroep case type tracks the municipality's actions (verweerschrift, zitting, uitspraak) but does not integrate with court systems.

**Rationale**: The municipality has limited control over court proceedings. Beroep tracking is primarily about document management (verweerschrift) and recording outcomes (uitspraak). Full court integration would require external APIs that don't exist in a standard way.

## Risks / Trade-offs

| Risk | Mitigation |
|------|------------|
| Pre-seeded data conflicts with existing custom case types | Repair step uses idempotent import -- checks for existing objects by identifier before creating |
| AWB rules change | Workflow templates are versionable -- administrators can create new versions. Pre-seeded templates serve as a starting point |
| Deadline calculation edge cases (holidays, weekends) | V1 uses calendar days for 6-week deadlines (AWB uses calendar weeks). Working-day calculation for acknowledgment deadline uses a simple weekday check. Holiday calendar integration is a future enhancement |
| Complex advisory committee workflows vary by municipality | Pre-seeded workflow is a common baseline. Municipalities can customize via the workflow editor |
| Large register JSON file | The 4 new schemas add moderate size. OpenRegister handles this efficiently |

## Migration Plan

1. Add 4 new schemas to `procest_register.json`: `objection`, `hearingSession`, `advisoryReport`, `appealDecision`
2. Add pre-seeded case type data (Bezwaar, Beroep) with status types, role types to the register JSON
3. Add pre-seeded workflow templates for both case types to the register JSON
4. Create `bezwaar.js` Pinia store module with CRUD for all bezwaar entities + deadline calculation
5. Create Vue components: `BezwaarIntakeForm.vue`, `HearingPanel.vue`, `AdvisoryReportPanel.vue`, `BezwaarDecisionForm.vue`, `BeroepEscalationPanel.vue`, `BezwaarTimeline.vue`
6. Integrate components into existing case detail view with case-type-conditional rendering
7. Repair step runs on app update -- automatically seeds bezwaar/beroep data

**Rollback**: Remove schemas from register JSON and redeploy. Existing bezwaar data remains in OpenRegister but becomes orphaned (no UI). No data loss.

## Open Questions

- Should the bezwaar deadline calculation account for Dutch public holidays? V1 uses calendar weeks (AWB standard), but acknowledgment deadlines use working days.
- Should the pre-seeded workflow templates be marked as read-only (cannot delete the base version), or should administrators have full control?
