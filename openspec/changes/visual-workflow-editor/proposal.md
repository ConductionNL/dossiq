# Proposal: visual-workflow-editor

## Summary

Procest needs a drag-and-drop visual editor so tenant administrators can author workflow definitions without hand-editing JSON. The `workflow-definition-model` and `role-based-step-routing` specs deliver the data contract, but today the only authoring surface is the form-based `WorkflowDefinitionsTab.vue` shipped with V1 of the definition model. Administrators are forced to imagine the graph in their head, type status UUIDs into form fields, and iterate by save-and-refresh. This change introduces `VisualEditor.vue` — a graph-based authoring canvas built on `vue-flow` — that reads and writes the same `workflowTemplate` objects, validates them live against the existing model, and persists changes through the same `WorkflowDefinitionService` lifecycle (draft → published → deprecated).

## Why

- **JSON-editing is hostile to non-developers.** Tenant admins are domain experts (vergunningverleners, bezwaarbehandelaars), not developers — handing them a UUID-laden form blocks adoption of the workflow-definition-model itself.
- **18 NL/BE tenders require a "no-code workflow editor".** The form UI from V1 ticks the box for *some* configurability but explicitly loses on the "visual / drag-and-drop / process designer" criteria scored in tender requirements.
- **Validation feedback is invisible in form mode.** Orphan states, missing final status, and circular routes are only caught at save time; admins lose context and trust.
- **Re-use over rebuild.** Procest already ships Vue 2.7 — the `vue-flow` graph library targets exactly that and is the lowest-cost path to a production-grade canvas; no new framework, no migration.

## What Changes

- New `VisualEditor.vue` route under `Admin > Zaaktypen > {type} > Workflow > Visual`.
- New `vue-flow` (latest 1.x) frontend dependency.
- New node-type components (`StatusNode`, `DecisionNode`, `ParallelNode`, `EndNode`) and a single `TransitionEdge` component.
- New left-side `PaletteSidebar.vue` (draggable node templates) and right-side `PropertiesPanel.vue` (live form for the selected node/edge).
- New live `WorkflowValidator` service (frontend) wrapping the canonical rules from `workflow-definition-model` and surfacing errors as overlay badges.
- New import/export round-trip — the editor reads and writes the exact `workflowTemplate` JSON schema; no custom intermediate format.
- New "Save as new version" flow that re-uses `WorkflowDefinitionService` lifecycle hooks (draft on save, publish via explicit action).

## Affected Projects

- [x] Project: `procest` — Frontend only. Adds `vue-flow`, four node components, two panel components, the editor view, and a frontend validator. No backend changes; persistence goes through the existing `WorkflowDefinitionService` and `WorkflowDefinitionController`.

## Scope

### In Scope (V1)

- Drag-and-drop canvas with status / decision / parallel / end node types and a single conditional transition edge type.
- Palette + canvas + properties-panel layout, mirrors the data model 1:1.
- Live validation overlay (errors red, warnings yellow), reusing existing model rules.
- Save as draft / publish as version flow via existing `WorkflowDefinitionService`.
- Import existing JSON definition; export current canvas state to JSON.
- Read-only preview mode for published versions.

### Out of Scope

- **Guard expression IDE** — owned by `status-transition-engine`; the editor surfaces guards as opaque rule objects.
- **Routing-rule editor inside nodes** — owned by `role-based-step-routing` (T06); the visual editor slots that component in unchanged.
- **Cross-tenant template marketplace** — Enterprise tier, future spec.
- **Backend changes** — V1 reuses the existing CRUD + lifecycle service exactly as-is.

## Dependencies

- `workflow-definition-model` — provides the `workflowTemplate` schema, lifecycle, and CRUD service consumed unchanged.
- `role-based-step-routing` — provides the `RoutingRuleEditor.vue` that slots into the properties panel for steps and transitions.
- `vue-flow` (new npm dep) — graph library; rationale in `design.md`.
