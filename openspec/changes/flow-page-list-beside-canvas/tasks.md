# Tasks: flow-page-list-beside-canvas

Decision (24 Nov 2026): the List view is built in dossiq beside the canvas it mirrors
(`src/views/settings/components/WorkflowList.vue` over `src/utils/flowList.js`), not as a
nextcloud-vue list mode. The canvas, the step panel and the flow definition's shape
(`workflowTemplate.steps` with `status`/`order`) are still dossiq's own editor; no library
flow-definition widget exists to add a list mode to. Both views read and write the one
template through the workflow store, which is what the requirement asks. Moving the whole
editor into the library is its own change.

Decision (24 Nov 2026): on a status step, "Step name" is the status's own name, "Name of the
status" is its public label (what a resident reads), "Deadline in this status" is
`phaseTermDays`, and "Next status" is the target of the status's first outgoing transition
(added when it has none, retargeted when it has one, removed when cleared). Choosing a status
on the canvas opens the same panel.

### Task 1: Header row and the Canvas | List switch
- **spec_ref**: `openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas`
- **files**: `src/manifest.json` (CaseTypeFlow page), `l10n/en.json`, `l10n/nl.json`
- [x] Header: case type title, version pill, Check, More, Save, as board DqZaaktypeFlow (`WorkflowTab` `boardHeader`, on the flow page only; the case type page keeps its toolbar; test `tests/vitest/flowPageHeaderRow.spec.js`)
- [x] Canvas | List switch with the board's line under it (`WorkflowTab.vue`)

### Task 2: The List view
- **spec_ref**: `openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas`
- **files**: nextcloud-vue flow widget (list mode), `src/manifest.json`
- [x] Trigger first, then statuses and steps in order, numbered, with kind pills (a template without a trigger reads "When: Case created"; a step without a known `kind` is a Task)
- [x] Move up/down, edit and delete per row; changes write the same flow definition as the canvas (`workflowStore.moveStep`, the canvas's own step panel and delete)
- [x] Test: `tests/vitest/flowPageListBesideCanvas.spec.js` over the rows, the move, the store write and the editor in list mode (the list mode is dossiq's, see Decision)

### Task 3: The step side panel
- **spec_ref**: `openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-the-step-panel-follows-the-board-in-both-views`
- **files**: nextcloud-vue step panel, `src/manifest.json`
- [x] Fields and buttons as the board draws them, in both views (`StatusStepPanel.vue` for a status step: Step name, Name of the status, Steps in this status, Next status, Deadline in this status, Done, Delete step; a task step keeps `StepConfigPanel` and gains Done; the same panel opens from a List row and from the canvas)
- [x] Test: the panel's fields per step kind (`tests/vitest/flowPageListBesideCanvas.spec.js` StatusStepPanel and editor status step; `workflowPanelsThroughTheDom.spec.js` Done on a task step)

### Task 4: Live pass
- [ ] compdiff of DqZaaktypeFlow on the demo stack, English board
