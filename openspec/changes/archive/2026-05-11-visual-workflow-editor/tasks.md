# Tasks: visual-workflow-editor

## 1. Foundation

### T01: Install graph library and pin version
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-graph-library-foundation`
- **files**: `package.json`, `package-lock.json`
- **acceptance_criteria**:
  - GIVEN `package.json` WHEN inspected THEN it declares `@vue-flow/core` at version `^1.x` and the matching `@vue-flow/background` and `@vue-flow/controls` peer add-ons.
  - GIVEN `npm run build` THEN the bundle includes `vue-flow` and no peer-dep warnings are emitted.
- [ ] Add `@vue-flow/core`, `@vue-flow/background`, `@vue-flow/controls`, `dagre` to dependencies; run `npm i` and commit lockfile.

### T02: VisualEditor.vue scaffold and routing
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-editor-route-and-shell`
- **files**: `src/views/admin/VisualEditor.vue`, `src/router/admin.js`
- **acceptance_criteria**:
  - GIVEN an admin navigates to `Admin > Zaaktypen > {type} > Workflow > Visual` WHEN the route resolves THEN `VisualEditor.vue` renders with the three-pane layout (palette / canvas / properties).
  - GIVEN the route loads with a valid `workflowDefinition` id WHEN mounted THEN the active published version is fetched and rendered read-only.
- [ ] Add the route, lazy-load the view, wire the breadcrumb and tab integration into `CaseTypeWorkflow.vue`.

## 2. Canvas & Palette

### T03: PaletteSidebar component
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-node-palette`
- **files**: `src/components/visual-editor/PaletteSidebar.vue`
- **acceptance_criteria**:
  - GIVEN the palette is rendered WHEN inspected THEN it lists exactly four draggable node templates: Status, Decision, Parallel, End.
  - GIVEN an admin drags a Status template onto the canvas WHEN dropped THEN a new draft status node is inserted at the drop coordinates.
- [ ] Implement HTML5 drag-and-drop with type-specific data payloads consumed by the canvas drop handler.

### T04: Canvas with custom node and edge types
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-canvas-rendering`
- **files**: `src/components/visual-editor/Canvas.vue`, `src/components/visual-editor/nodes/{StatusNode,DecisionNode,ParallelNode,EndNode}.vue`, `src/components/visual-editor/edges/TransitionEdge.vue`
- **acceptance_criteria**:
  - GIVEN a workflow template is loaded WHEN rendered THEN every `workflowTemplate.steps[].status` becomes a node and every `transitions[]` becomes a `transition` edge.
  - GIVEN the admin drags a connection from a node output handle to another node input handle WHEN released THEN a new transition is added to the working copy.
- [ ] Register the four node types and the single edge type with `<VueFlow>`; implement handles, selection, hover styles.

## 3. Properties Panel

### T05: PropertiesPanel and node/edge editors
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-properties-panel`
- **files**: `src/components/visual-editor/PropertiesPanel.vue`, `src/components/visual-editor/properties/{StatusProperties,TransitionProperties,DecisionProperties}.vue`
- **acceptance_criteria**:
  - GIVEN a status node is selected WHEN the panel renders THEN it shows the editable fields `title`, `isFinal`, the step list with reorder, and slots the existing `RoutingRuleEditor.vue` for `assigneeRole`.
  - GIVEN a transition edge is selected WHEN the panel renders THEN it shows `label`, `guards[]`, and slots `RoutingRuleEditor.vue` for `allowedRoles`.
  - GIVEN no selection WHEN the panel renders THEN it shows the workflow-level metadata (`title`, `description`, current version, `isDraft`).
- [ ] Wire selection state from `VisualEditor.vue` through props; persist edits back to the working copy on blur or debounce.

## 4. Validation & Save

### T06: WorkflowValidator service + overlay
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-live-validation`
- **files**: `src/services/visual-editor/WorkflowValidator.js`, `src/components/visual-editor/ProblemsPanel.vue`
- **acceptance_criteria**:
  - GIVEN the working copy mutates WHEN the validator runs THEN it emits issues for `ORPHAN_NODE`, `NO_FINAL_STATUS`, `UNREACHABLE_FINAL`, `CYCLE_NO_EXIT`, `DUPLICATE_TRANSITION`, `MISSING_LABEL`.
  - GIVEN at least one `error`-level issue exists WHEN the admin clicks "Publiceren" THEN the action is blocked and the offending node/edge is highlighted.
- [ ] Pure JS implementation, no backend round-trip; reachability via BFS from the initial status.

### T07: Save, publish, and discard wiring
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-save-as-version-flow`
- **files**: `src/services/visual-editor/WorkflowDefinitionClient.js`, `src/views/admin/VisualEditor.vue`
- **acceptance_criteria**:
  - GIVEN the admin clicks "Bewerken" WHEN no draft exists THEN the client calls `POST /api/workflow-definition` with `isDraft: true` and `version: active.version + 1`.
  - GIVEN unsaved changes WHEN 2 s elapses since the last edit THEN the draft is auto-saved via `PUT /api/workflow-definition/{id}`.
  - GIVEN the admin clicks "Publiceren" AND no error-level validation issues exist WHEN confirmed THEN the client calls `POST /api/workflow-definition/{id}/publish` and the prior active version is deprecated.
- [ ] Use the existing `WorkflowDefinitionController` endpoints; no new backend routes.

## 5. Import / Export / Preview

### T08: JSON import/export
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-import-export-round-trip`
- **files**: `src/components/visual-editor/ImportExportDialog.vue`, `src/services/visual-editor/JsonRoundTrip.js`
- **acceptance_criteria**:
  - GIVEN a valid `workflowTemplate` JSON file WHEN the admin imports it THEN every status, transition, step, and guard appears on the canvas without data loss.
  - GIVEN the admin exports the current working copy WHEN the file is re-imported into a fresh editor THEN the resulting canvas is structurally identical (round-trip safe).
  - GIVEN an invalid JSON document WHEN imported THEN the dialog shows the first schema-validation error and refuses the import.
- [ ] Validate against the `workflowTemplate` schema fetched from OpenRegister at runtime.

### T09: Auto-layout fallback (dagre)
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-auto-layout-when-coordinates-missing`
- **files**: `src/services/visual-editor/AutoLayout.js`
- **acceptance_criteria**:
  - GIVEN a workflow template loads WHEN no `layout` block exists THEN `dagre` computes left-to-right hierarchical coordinates and the canvas renders nodes at those positions.
  - GIVEN the working copy saves THEN the computed `layout` block is persisted alongside the workflow template.
- [ ] Use `dagre` from T01; no manual positioning of seeded workflows required.

### T10: Preview (read-only) mode
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-read-only-preview-of-published-versions`
- **files**: `src/views/admin/VisualEditor.vue`
- **acceptance_criteria**:
  - GIVEN the active published version loads WHEN no draft is open THEN the canvas is read-only (no drag/edit/delete), the palette is hidden, and the properties panel shows fields as disabled.
  - GIVEN the admin clicks "Bewerken" WHEN no draft exists THEN preview mode flips to edit mode by creating a fresh draft.
- [ ] Single boolean `mode: 'preview' | 'edit'` toggles palette visibility, handle interactivity, and properties-panel disabled state.

## 6. Verification

### V01: Component tests for canvas + palette + properties
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md`
- **files**: `tests/unit/visual-editor/*.spec.js`
- **acceptance_criteria**:
  - Palette renders four templates and emits drag events with correct payloads.
  - Canvas renders nodes and edges from a sample working copy; new-edge drag creates the transition.
  - Properties panel slots `RoutingRuleEditor` for the right contexts.
- [ ] Use `@vue/test-utils` + `vitest`; mock `vue-flow` where needed.

### V02: WorkflowValidator unit tests
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md#requirement-live-validation`
- **files**: `tests/unit/visual-editor/WorkflowValidator.spec.js`
- **acceptance_criteria**: Each of the six validator codes has at least one positive and one negative test; reachability uses BFS; cycle detection covers self-loops and multi-node cycles.
- [ ] ≥90% statement coverage on `WorkflowValidator.js`.

### V03: Playwright end-to-end happy path
- **spec_ref**: `openspec/changes/visual-workflow-editor/specs/visual-workflow-editor/spec.md`
- **files**: `tests/e2e/visual-workflow-editor.spec.ts`
- **acceptance_criteria**: An admin opens the editor for a seeded zaaktype, drags two status nodes, connects them, sets the second as final, publishes, and reloads the page to see the published version rendered correctly.
- [ ] Run against `browser-3` in the shared pool.
