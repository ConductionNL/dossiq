# Proposal: workflow-editor-integration

kind: code -- finishes integrating the visual workflow (state-machine) editor
that already ships inside the case-type admin, removes a second dead
implementation that cannot even build, and closes the correctness gaps
between the canvas and the actual publish/validation engine.

## Why

Zero-coding visual process design is a headline feature of competing
zaaksysteem products (xxllnc Zaken) and low-code self-configuration is a
top procurement criterion. Procest already ships a visual canvas
(`src/views/settings/WorkflowEditor.vue`), wired into
`CaseTypeDetail.vue`'s "Workflow" tab via `WorkflowTab.vue` -- this is
**not** the dead/unintegrated code the original audit assumed. Verifying
against HEAD instead found:

- A **second, fully dead** editor implementation --
  `src/components/workflow/VisualWorkflowEditor.vue` plus its helpers
  (`WorkflowCanvas.vue`, `NodePalette.vue`, `NodeProperties.vue`,
  `EdgeProperties.vue`, `validator.js`) -- explicitly unwired in
  `src/customComponents.js`/`src/registry.js` because
  `@vue-flow/{core,controls,background}` v1.x are Vue-3-only and break
  procest's Vue 2.7 webpack build (272 errors, per the code comment). It
  cannot ship as-is; it is pure dead weight and its own validation logic
  (`validator.js`) models the graph on the wrong entities (treats `step`
  as the graph node with a `step.isFinal` field that does not exist in
  the `workflowTemplate` schema -- `isFinal` lives on `statusType`).
- The **live** canvas's own validation
  (`workflow.js` store `validateWorkflow()`) only checks "has transitions"
  and a weak "no initial status" heuristic -- it has no access to the
  `statusType` node list at all, so it cannot detect the rules the spec
  promises (missing final status, unreachable final, orphan nodes, cycles
  without exit, duplicate/dangling transitions).
- The canvas's **publish** action
  (`workflowStore.publishVersion()`) bypasses the backend entirely: it
  writes `isDraft:false`/`isActive:true` flags straight through the
  generic object store instead of calling
  `POST /api/workflow-definitions/{id}/publish`
  (`WorkflowDefinitionController::publish` ->
  `WorkflowDefinitionService::publish()`). That backend method is the
  *only* place that enforces referential integrity (transitions must
  reference statuses of the same case type), freezes role-authorization
  group ids onto transitions (ADR-022, the OR-enforced "only group X may
  perform this transition" rule), deprecates the previously active
  version, and pins `caseType.workflowDefinition` -- none of which happen
  when a definition is published from the canvas today.
- Two CRUD gaps: deleting a status node has no UI at all, and the store's
  `removeStep()` action exists but is never called from any component.
- The canvas is entirely mouse/pointer-driven (drag-drop palette, node
  selection, port-to-port connection drawing) with zero keyboard path,
  unlike the just-shipped Workflow Board keyboard pattern
  (`kanban-board-keyboard-status-transition`: a parallel, keyboard
  focusable `NcActions` control that reuses the same handler as the drag
  gesture).
- Zero automated test coverage exists for the editor, its store actions,
  or the (dead) validator.

## What Changes

- **Delete the dead editor tree**: `src/components/workflow/` (all five
  files) and the `@vue-flow/*` dependencies from `package.json`. Remove
  the now-stale "kept out of registry"/"temporarily unwired" comments in
  `src/customComponents.js` and `src/registry.js`.
- **Extract + correct validation**: new pure util
  `src/utils/workflowGraphValidation.js` operating on the real graph
  (`statusType` nodes + `transitions[]`), implementing: no final status,
  unreachable final, dangling/foreign transition reference, duplicate
  transition, orphan node, cycle without exit to a final status. Wired
  into `workflow.js`'s `validateWorkflow(statusNodes)` (now accepting the
  node list, which only the component had) and surfaced unchanged through
  the existing `WorkflowValidationBanner.vue`.
- **Fix the publish path**: `workflowStore.publishVersion()` now calls the
  canonical `POST /api/workflow-definitions/{id}/publish` endpoint instead
  of writing flags directly, so the canvas gets the same referential
  integrity check, role-authorization freeze, previous-version deprecation
  and case-type pin as the generic (non-canvas) publish action. Client
  validation still runs first for fast, itemised feedback; the backend
  call is now an authoritative backstop instead of being skipped.
- **Close the CRUD gaps**: wire `removeStep()` to a "Delete step" control
  in `StepConfigPanel.vue`; add a `removeStatusNode()` store action
  (cascades: drops the node's steps and incident transitions) plus a
  "Delete status" control, guarded the same way
  `StatusesTab.vue::deleteStatusType()` already guards it (must leave at
  least one final status).
- **A11y**: mirror the kanban pattern -- `WorkflowNode.vue` becomes
  focusable/`Enter`/`Space`-activatable for selection, and gets a
  keyboard-reachable `NcActions` menu (visible-text `NcActionButton`
  items, not icon-only) for "Connect to...", "Disconnect from...", "Add
  step" and "Delete status", reusing the exact same handlers as the mouse
  path. `WorkflowPalette.vue` gets a visible "Add status node" `NcButton`
  as a keyboard alternative to drag-and-drop.
- **Tests**: vitest for `workflowGraphValidation.js` (every rule) and a
  serialization round-trip; component smoke tests for `WorkflowEditor.vue`
  (renders a loaded definition's status nodes; `validate()` blocks the
  exact gate `WorkflowTab.vue::publish()` calls on an invalid graph) via a
  new scoped jsdom test lane; a Playwright e2e spec following the existing
  "skip gracefully when data isn't present" convention for canvas
  rendering and keyboard-reachability of the node actions menu / palette
  "Add status node" button -- deliberately non-destructive (no mutation
  against the live/shared register), so the open-edit-save-reopen round
  trip and blocked-publish-on-invalid behaviour are proven by the vitest
  smoke tests instead.

## Capabilities

### Modified Capabilities

- `visual-workflow-editor`: corrects the stale "V1, not yet integrated"
  purpose note, adds delete-node/delete-step, real-time validation backed
  by the actual engine constraints, keyboard operability, and a
  single-write-path publish flow.

## Impact

- **Frontend**: `src/components/workflow/**` (deleted, 6 files),
  `src/views/settings/WorkflowEditor.vue`,
  `src/views/settings/components/{WorkflowNode,WorkflowPalette,
  StepConfigPanel}.vue`, `src/store/modules/workflow.js`,
  `src/utils/workflowGraphValidation.js` (new), `src/customComponents.js`,
  `src/registry.js`, `package.json` (drop `@vue-flow/*`).
- **Specs**: `openspec/specs/visual-workflow-editor/spec.md` (delta via
  this change).
- **Tests**: `tests/vitest/workflowGraphValidation.spec.js` (new),
  `tests/vitest/workflowEditorSmoke.spec.js` (new; also adds a scoped
  jsdom component-test lane: `vitest.config.js`, `tests/vitest/setup.js`,
  `tests/vitest/stubs/conduction-nextcloud-vue.js`, and
  `@vitejs/plugin-vue2`/`@vue/test-utils`/`jsdom` devDependencies),
  `tests/e2e/spec-coverage/workflow-editor-canvas.spec.ts` (new).
- **i18n**: new `t('procest', ...)` strings added to `l10n/en.json` +
  `l10n/nl.json` as English/Dutch pairs.
- **Out of scope**: reordering steps via drag (already implemented,
  untouched), import/export JSON flow (already implemented, untouched),
  migrating the canvas off hand-rolled SVG/DOM to a maintained graph
  library -- no such library is both Vue-2-compatible and vetted; tracked
  as a follow-up, not blocking this integration.
