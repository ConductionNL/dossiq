## ADDED Requirements

### Requirement: The task page is a manifest detail page

The task page SHALL be a `type: "detail"` manifest page with `entitySource: "tasks"`, on the route `/tasks/:id` with the
page id `TaskDetail`. Its header SHALL show the task's state and, for a high or urgent task, its priority as pills, a
breadcrumb back to Tasks, and the engine's verbs read off the task, Finish posted to dossiq's own completion route. Its
body SHALL have an Overview tab (description, checklist, appointments, notes) and a History tab (the engine's audit
trail), and its side column the task facts, the case card, the waiting-case card and the task history. A task that is
missing or may not be read SHALL show one not-found state. The notes, appointments and history leaves and the
waiting-case section SHALL be detail widgets that read the task the page hands them as `objectId`.

#### Scenario: A notification link opens the task on the manifest page
@e2e exclude the e2e rig seeds no engine task with a checklist and a waiting run; the unit specs `tests/vitest/taskDetailManifestPage.spec.js` and `tests/vitest/taskLeavesAsDetailWidgets.spec.js` assert the page config and the widgets, and `tests/e2e/case-task-pane.spec.ts` opens `/tasks/:id`

- **GIVEN** a notification that links to `/apps/dossiq/tasks/<uuid>`
- **WHEN** the handler opens it
- **THEN** the `TaskDetail` page reads that task from the engine and shows its state, its verbs and its leaves
