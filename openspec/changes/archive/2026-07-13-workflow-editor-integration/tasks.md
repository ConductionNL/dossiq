# Tasks: workflow-editor-integration

## Deduplication Check

- [x] **DC01**: Searched `openspec/specs/visual-workflow-editor/spec.md` --
  status `done`, Purpose note says the canvas is "not yet integrated into
  the admin settings". Verified against HEAD this is stale: the canvas
  (`WorkflowEditor.vue`) IS wired into `CaseTypeDetail.vue`'s Workflow tab
  via `WorkflowTab.vue`. The real gap is a second, dead implementation
  (`src/components/workflow/VisualWorkflowEditor.vue` + helpers) that
  cannot build under Vue 2.7 (`@vue-flow` is Vue-3-only), plus validation/
  publish-path/a11y/CRUD gaps in the wired component. This change corrects
  the spec rather than duplicating it.
- [x] **DC02**: Checked `openspec/specs/workflow-definition-model/spec.md`
  and `openspec/specs/workflow-definition-engine/spec.md` -- neither
  covers editor UI or client-side validation; no overlap.
- [x] **DC03**: Checked `kanban-board-keyboard-status-transition` (active
  change) -- establishes the keyboard a11y pattern this change mirrors
  for the canvas; no functional overlap (different views).

## 1. Delete dead code

- [x] **T01**: Delete `src/components/workflow/VisualWorkflowEditor.vue`,
  `WorkflowCanvas.vue`, `NodePalette.vue`, `NodeProperties.vue`,
  `EdgeProperties.vue`, `validator.js` (whole directory).
  - `@spec openspec/changes/workflow-editor-integration/tasks.md#T01`
- [x] **T02**: Remove `@vue-flow/core`, `@vue-flow/background`,
  `@vue-flow/controls` from `package.json` dependencies.
  - `@spec openspec/changes/workflow-editor-integration/tasks.md#T02`
- [x] **T03**: Remove the stale "kept out of registry"/"temporarily
  unwired" comments and commented-out import/registration in
  `src/customComponents.js` and `src/registry.js`.
  - `@spec openspec/changes/workflow-editor-integration/tasks.md#T03`

## 2. Validation

- [x] **T04**: New `src/utils/workflowGraphValidation.js` --
  `validateWorkflowGraph({ statusNodes, transitions })` implementing:
  NO_FINAL_STATUS, UNREACHABLE_FINAL, DANGLING_EDGE,
  DUPLICATE_TRANSITION, ORPHAN_NODE, CYCLE_NO_EXIT. Operates on the real
  schema (statusType nodes with `isFinal`; NOT the dead validator.js's
  wrong `step.isFinal` model).
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation`
- [x] **T05**: `workflow.js` `validateWorkflow(statusNodes)` delegates to
  the new util instead of its inline weak check.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation`
- [x] **T06**: `WorkflowEditor.vue::validate()` passes `this.statusNodes`
  through; `WorkflowTab.vue::publish()` already calls
  `$refs.editor.validate()` first (unchanged call site).
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation`

## 3. Publish path

- [x] **T07**: `workflow.js` `publishVersion(templateId)` now calls
  `POST /apps/procest/api/workflow-definitions/{id}/publish` instead of
  writing flags via `objectStore.saveObject`. Removes the manual
  "deactivate other active versions" loop (the backend now does this
  atomically).
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-publish-uses-the-canonical-write-path`

## 4. CRUD gaps

- [x] **T08**: `workflow.js` `removeStatusNode(statusId)` -- drops steps
  where `step.status === statusId` and transitions where
  `fromStatus`/`toStatus === statusId` from the working copy.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas`
- [x] **T09**: `WorkflowEditor.vue::onDeleteStatusNode(statusId)` --
  guards "at least one final status must remain" (mirrors
  `StatusesTab.vue::deleteStatusType`), confirms, calls
  `objectStore.deleteObject('statusType', statusId)` then
  `workflowStore.removeStatusNode(statusId)`.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas`
- [x] **T10**: `StepConfigPanel.vue` -- add "Delete step" `NcButton`
  (visible text), emits `delete`; `WorkflowEditor.vue::onStepDelete(id)`
  calls `workflowStore.removeStep(id)` and closes the panel.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-step-configuration-panel`

## 5. Keyboard operability

- [x] **T11**: `WorkflowNode.vue` -- `role="button" tabindex="0"` +
  `@keydown.enter`/`@keydown.space.prevent` alongside the existing
  `@click` for selection.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas`
- [x] **T12**: `WorkflowNode.vue` -- `NcActions` menu (visible-text
  `NcActionButton`s): "Connect to {node}" per unconnected other node,
  "Disconnect from {node}" per existing outgoing transition, "Delete
  status". Emits reused by `WorkflowEditor.vue` handlers already wired
  for the mouse path.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas`
- [x] **T13**: `WorkflowPalette.vue` -- visible "Add status node"
  `NcButton` emitting `add-status`; `WorkflowEditor.vue::onAddStatusKeyboard()`
  reuses the same statusType-create logic as the drop handler, placed at
  a default computed position.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas`

## 6. Tests

- [x] **T14**: `tests/vitest/workflowGraphValidation.spec.js` -- every
  rule (pass case + each error/warning triggered individually) plus a
  serialization round-trip check.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation`
- [x] **T14b**: `tests/vitest/workflowEditorSmoke.spec.js` -- component
  smoke tests for `WorkflowEditor.vue`: renders one status node per
  loaded `statusType` (open-definition round trip), and `validate()`
  returns `false` + records `NO_FINAL_STATUS` for an invalid graph --
  the exact gate `WorkflowTab.vue::publish()` calls before saving/
  publishing. Plus a `WorkflowValidationBanner.vue` render test (the
  per-issue message text a user acts on). Required adding a scoped jsdom
  component-test lane: `@vitejs/plugin-vue2` + `@vue/test-utils` +
  `jsdom` devDependencies, a `// @vitest-environment jsdom` per-file
  pragma (the suite default stays `node` for the pure-logic tests), a
  `tests/vitest/setup.js` global `t`/`n` stub, and a
  `tests/vitest/stubs/conduction-nextcloud-vue.js` alias (the published
  `@conduction/nextcloud-vue` CJS bundle `require()`s raw `.vue` files,
  which Vite's transform cannot consume) -- recipe mirrored from
  `launchpad/vitest.config.js`, the sibling app that already solved this
  for the same Vue 2.7 + `@nextcloud/vue` stack.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation`
- [x] **T15**: `tests/e2e/spec-coverage/workflow-editor-canvas.spec.ts` --
  verify the Workflow tab renders the canonical canvas (or its empty
  state), a status node is keyboard-focusable (`role="button"`,
  `tabindex="0"`) with a keyboard-reachable actions menu, and the
  palette's "Add status node" button is keyboard-focusable; follows the
  existing "skip gracefully when data isn't present" convention (matches
  `kanban-board-keyboard-status-transition.spec.ts`) and is deliberately
  non-destructive (no mutation committed against the live/shared
  register -- adding a status node persists a real `statusType` object
  immediately). The open-edit-save-reopen round trip and
  blocked-save-on-invalid behaviour are proven directly against the real
  component in T14b instead.
  - `@spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas`

## 7. i18n

- [x] **T16**: Add every new `t('procest', ...)` string as an English/Dutch
  pair to `l10n/en.json` + `l10n/nl.json`; run `npm run test:l10n` to
  verify no gaps remain.
  - `@spec openspec/changes/workflow-editor-integration/tasks.md#T16`

## 8. Verification

- [x] **T17**: `npm run lint`, `npm test` (vitest), `npm run build`,
  `composer check:strict` (if any PHP touched -- none expected), full
  green suite.
  - `@spec openspec/changes/workflow-editor-integration/tasks.md#T17`
