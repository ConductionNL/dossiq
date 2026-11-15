# Tasks: task-page-on-the-manifest

### Task 1: The task leaves are detail widgets
- **spec_ref**: `openspec/changes/task-page-on-the-manifest/specs/task-management/spec.md#requirement-the-task-page-is-a-manifest-detail-page`
- **files**: `src/registry.js`, `src/components/tasks/TaskNotesLeaf.vue`, `src/components/tasks/TaskEventsLeaf.vue`, `src/components/tasks/TaskAuditLeaf.vue`, `src/components/flow/TaskWaitingCaseSection.vue`
- [x] Implement: `task-notes`, `task-events`, `task-audit`, `task-waiting-case` widget entries; each leaf reads `objectId` when no `taskId` is given; the audit leaf opens with `content.open`
- [x] Test: `tests/vitest/taskLeavesAsDetailWidgets.spec.js`

### Task 2: TaskDetail is a manifest detail page
- **spec_ref**: `openspec/changes/task-page-on-the-manifest/specs/task-management/spec.md#requirement-the-task-page-is-a-manifest-detail-page`
- **files**: `src/manifest.json`, `src/registry.js`, `src/views/tasks/TaskDetailView.vue`
- [x] Implement: the `TaskDetail` page as `type: "detail"` with `entitySource: "tasks"`; TaskDetailView removed
- [x] Test: `tests/vitest/taskDetailManifestPage.spec.js`
