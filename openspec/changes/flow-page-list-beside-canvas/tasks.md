# Tasks: flow-page-list-beside-canvas

### Task 1: Header row and the Canvas | List switch
- **spec_ref**: `openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas`
- **files**: `src/manifest.json` (CaseTypeFlow page), `l10n/en.json`, `l10n/nl.json`
- [ ] Header: case type title, version pill, Check, More, Save, as board DqZaaktypeFlow
- [ ] Canvas | List switch with the board's line under it

### Task 2: The List view
- **spec_ref**: `openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas`
- **files**: nextcloud-vue flow widget (list mode), `src/manifest.json`
- [ ] Trigger first, then statuses and steps in order, numbered, with kind pills
- [ ] Move up/down, edit and delete per row; changes write the same flow definition as the canvas
- [ ] Test: a vitest over the page config and a library test for the list mode

### Task 3: The step side panel
- **spec_ref**: `openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-the-step-panel-follows-the-board-in-both-views`
- **files**: nextcloud-vue step panel, `src/manifest.json`
- [ ] Fields and buttons as the board draws them, in both views
- [ ] Test: the panel's fields per step kind

### Task 4: Live pass
- [ ] compdiff of DqZaaktypeFlow on the demo stack, English board
