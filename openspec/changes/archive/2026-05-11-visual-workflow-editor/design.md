# Design: visual-workflow-editor

## Library Choice — vue-flow

**Decision: adopt `@vue-flow/core` 1.x as the graph rendering and interaction library.**

| Option | Verdict | Rationale |
|--------|---------|-----------|
| `@vue-flow/core` (1.x) | **Selected** | Native Vue 2.7 + Vue 3 support via the same package; declarative `<VueFlow>` component with slots for custom nodes/edges; built-in pan/zoom, mini-map, controls, and connection handles; MIT licensed; ~40KB gzipped; mature ecosystem (additional add-ons for background, controls, mini-map); actively maintained (last release < 60 days at time of writing). |
| `svelte-flow` | Rejected | Wrong framework — Procest is Vue 2.7. Would force a second runtime. |
| `cytoscape.js` | Rejected | General-purpose graph library; lacks first-class Vue bindings; node rendering is canvas-based, not DOM, so embedding Vue form components inside nodes is awkward. Heavier API surface for a UI-focused use case. |
| `jsplumb-toolkit` | Rejected | Commercial license for the toolkit edition required for the features we need (mini-map, undo, smart routing). |
| Roll our own SVG canvas | Rejected | Six months of yak-shaving for table-stakes features (pan, zoom, connection handles, edge routing). |

`vue-flow` slots Vue components inside nodes, which means `StatusNode.vue` can host the same inline editors used elsewhere in Procest (badge, step count, validation icon). Edges accept a custom component too, used for the conditional `TransitionEdge` which shows the transition label and guard count inline.

## Architecture

```
┌── PaletteSidebar ──┐  ┌───── Canvas (<VueFlow>) ─────┐  ┌── PropertiesPanel ──┐
│  Status            │  │  ┌──────┐    ┌──────┐         │  │  Selected: Status    │
│  Decision          │  │  │Intake│───►│Behand│──►End    │  │  Title  [Intake   ]  │
│  Parallel          │  │  └──────┘    └──────┘         │  │  Final  [ ]          │
│  End               │  │                                │  │  Steps  [ + add  ]   │
│                    │  │  validation overlays appear    │  │  Routing rule [...]  │
└────────────────────┘  └────────────────────────────────┘  └──────────────────────┘
        │                            │                              │
        └──drag────────►drop on canvas─►mutate working copy ◄──edit──┘
                                          │
                                  WorkflowValidator (live)
                                          │
                                  WorkflowDefinitionService (save / publish)
```

The editor holds a single in-memory **working copy** of the `workflowTemplate`. All node/edge mutations write to that working copy synchronously. Saving the copy hits the existing `POST /api/workflow-definition` (draft) or `POST /api/workflow-definition/{id}/publish` endpoints — no new backend routes.

## Node Types

| Type | Maps to | Visual | Behaviour |
|------|---------|--------|-----------|
| `status` | `workflowTemplate.steps[].status` (StatusType reference) | Rounded rectangle with header (name) + body (step count badge + validation icon) | Has one input handle (top) and one output handle (bottom). Click selects, double-click renames inline. |
| `decision` | A `statusTransition` whose `guards` contain a `customExpression` | Diamond | Two output handles labelled "Ja" / "Nee"; renders the guard expression as a chip. |
| `parallel` | A fork point — multiple transitions out of the same status without guards | Vertical bar with thick stroke | Multiple output handles; renders as AND-split (every outgoing edge fires). |
| `end` | A status with `isFinal: true` | Rounded rectangle with double border | Only an input handle; no outgoing transitions allowed. |

## Edge Type

A single `transition` edge represents a `statusTransition`. It renders the label inline, a small badge with the guard count, and turns red when validation fails (e.g., dangling at one end). Clicking opens the edge in the properties panel where the existing `RoutingRuleEditor.vue` (from `role-based-step-routing`) is slotted in for `allowedRoles` configuration.

## Save-as-Version Flow

1. The editor opens with the **active published** version loaded read-only (preview mode).
2. The admin clicks "Edit" → editor enters draft mode, calling `WorkflowDefinitionService::createDraftFrom(activeVersion)`. A new draft `workflowTemplate` is created with `isDraft: true`, `version: active.version + 1`.
3. Every change auto-saves the working copy to the draft (debounced 2 s).
4. The admin clicks "Publiceren" → calls `POST /api/workflow-definition/{id}/publish`; the existing service handles the lifecycle transition, deactivates the prior version, and pins new cases to the new version.
5. The admin may "Verwerpen" → calls `DELETE /api/workflow-definition/{id}` (only allowed while `isDraft`).

In-flight cases keep using their pinned version — guaranteed by the model already.

## Validation Overlay

`WorkflowValidator` is a frontend service (no backend) that mirrors the canonical model rules and emits a list of `{nodeId, level, code, message}` issues whenever the working copy changes:

| Code | Level | Detected When |
|------|-------|---------------|
| `ORPHAN_NODE` | warning | Status has no incoming and no outgoing transitions |
| `NO_FINAL_STATUS` | error | Working copy has zero nodes with `isFinal: true` |
| `UNREACHABLE_FINAL` | error | At least one final status is not reachable from the initial status |
| `CYCLE_NO_EXIT` | warning | A cycle exists with no path out to any final status |
| `DUPLICATE_TRANSITION` | warning | Two transitions share the same `(fromStatus, toStatus)` pair |
| `MISSING_LABEL` | warning | A transition has no `label` |

Issues are rendered as overlay badges on the affected node/edge and listed in a collapsible "Problemen" panel at the bottom of the canvas. The "Publiceren" button is disabled while any `error`-level issue is present.

## Import / Export

- **Import**: an admin pastes or uploads a JSON document conforming to the `workflowTemplate` schema. The editor validates against the schema, then renders the graph; layout coordinates, if absent, are computed via `dagre` (left-to-right hierarchical layout) and persisted on first save.
- **Export**: the current working copy is downloaded as a JSON file conforming to the same schema; round-trip-safe with import.

## Layout Persistence

The schema gains an optional `layout` object on the `workflowTemplate`, keyed by node id (`{x, y}` per node). This is consumed only by the visual editor; absent layout triggers `dagre` auto-layout on first open. (No backend changes — the field is already free-form JSON inside the OpenRegister object.)

## Failure Modes

- **Save conflict** (another admin edited the same draft): the API returns 409; the editor shows a diff dialog and lets the user reload-and-discard or attempt to keep their copy.
- **Invalid JSON on import**: the editor refuses the import and points to the first schema-validation error.
- **vue-flow runtime error**: the editor falls back to the existing form-based `WorkflowDefinitionsTab.vue` with a banner explaining the fallback; no data loss because the working copy is JSON.
