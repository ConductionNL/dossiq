# Design: visual-workflow-editor

> ## ⚠️ CORRECTION (2026-07-16) — the library choice below rested on a false premise
>
> This document is archived and is kept verbatim as a record of what was decided.
> It is **factually wrong** in a way that cost a whole feature, so it must not be
> read as guidance. Corrected by ADR-065 (hydra:
> `openspec/architecture/adr-065-flow-engine-and-canvas.md`).
>
> **What this document claims (row 1 of the table below):**
> *"Native Vue 2.7 + Vue 3 support via the same package"*, and in `proposal.md`:
> *"the `vue-flow` graph library targets exactly that ... no new framework, no migration."*
>
> **What is actually true.** `@vue-flow/core` has published exactly `0.4.41` and
> then `1.0.0`–`1.48.2`. **Every single release declares
> `peerDependencies: { "vue": "^3.x" }`** — the lone 0.x is `^3.2.25`, current is
> `^3.3.0`. There has never been a Vue-2-compatible release of `@vue-flow/core`
> at any point in the package's history. The npm package literally named
> `vue-flow` is an unrelated 2016 state-management library.
>
> **What it cost.** The build failed with **272 errors** under procest's Vue 2.7
> base (`@vue-flow` imports `Fragment` / `Teleport` / `createElementVNode` /
> `toValue` from `vue`). The components were unwired in `customComponents.js`,
> the manifest page `WorkflowTemplateEditor` was left unresolvable at runtime,
> and this change was archived **as done while its code never ran once** — a
> textbook phantom-green (ADR-060, hydra).
> The dead components and the `@vue-flow/*` dependencies have since been removed
> from `development`.
>
> **Two process lessons, both cheap to apply:**
>
> 1. **A peer-dependency claim is checkable in one command.** `npm view
>    @vue-flow/core peerDependencies` would have refuted this table in seconds,
>    before any code was written. Verify the compatibility claim that a library
>    choice rests on — don't infer it from a README or a docs page.
> 2. **The rejected option won.** "Roll our own SVG canvas" is dismissed in row 5
>    below as *"six months of yak-shaving"*. Procest went on to hand-roll exactly
>    that, and `src/views/settings/WorkflowEditor.vue` (~722 LOC, Vue 2.7 native)
>    is **the only canvas here that has ever worked in production**. It is now the
>    extraction source for the shared `CnGraphCanvas` in nc-vue per ADR-065. The
>    cost estimate that justified rejecting it was wrong by roughly an order of
>    magnitude.
>
> The alternatives table below evaluates svelte-flow, cytoscape.js,
> jsplumb-toolkit and roll-your-own — but **no Vue-2-compatible flow library**.
> Vue 2.7 was treated as an immovable constraint rather than a decision variable,
> so Vue 3 was never considered. For the record, the one verified Vue-2-compatible
> option is `rete.js` + `rete-vue-plugin/vue2`; ADR-065 declines it because
> procest's hand-rolled canvas already works.

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
