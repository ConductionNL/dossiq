# Proposal: task-page-on-the-manifest

## Why

The task page (`/tasks/:id`, board DqTaak) is TaskDetailView, about 1,000 lines of custom view, because a detail page
could not read an engine task. Ruben's rule is that pages are manifest config over generic nextcloud-vue widgets. The
library now reads one task (`entitySource: "tasks"`, tasks-record-source), offers its verbs in the header
(record-source-verbs), ticks its checklist (record-checklist-widget) and draws a priority pill only for high and
urgent tasks (detail-pill-values). What is left is dossiq's own: the leaves that read task-anchored engine endpoints,
and the page itself.

## What changes

1. The notes, appointments and history leaves and the waiting-case section become detail widgets (`task-notes`,
   `task-events`, `task-audit`, `task-waiting-case`) that read the task the page hands them as `objectId`.
2. TaskDetail becomes a `type: "detail"` page on `entitySource: "tasks"`, on the same route (`/tasks/:id`) and page id
   (`TaskDetail`), so deep links and notifications keep working. TaskDetailView is removed once the page covers
   everything it did.

## What the custom view did (the no-loss list)

1. Read the task from the engine by the route id; one "This task is not there" state for missing and unreadable.
2. Title (display title, else title, else "Task").
3. State pill in the reader's words, purple while active and green when done.
4. Priority pill for high (warning) and urgent (error) only.
5. Breadcrumb Tasks / title.
6. Meta line: the case number and the deadline in words, the deadline left out once finished.
7. Header verbs read off the task: Finish, Pick up or Hand back, Cancel behind More; none on a finished task.
8. Finish through dossiq's own completion route (the case type's form and effects); the others to the engine.
9. A refused verb shows the engine's message as-is; a finished task gives a success message.
10. Overview and History tabs.
11. Description card.
12. Checklist card, "{done} of {total} done", ticks written to the engine, the engine's answer replacing the task,
    read-only once finished; items without an id left out.
13. Appointments leaf (task events endpoint).
14. Notes leaf: list, add, delete your own.
15. History tab: the engine's audit trail.
16. Side card Task: handler, team, due by, expires, priority in words, requested by, created; no card without facts.
17. Side card Case: title linking to the case, number and case type, handler and case deadline, status pill in its own
    colour, "To the case" link; no card for a task without a case.
18. Side card "A case is waiting on this task", for a task a flow run waits on.
19. Side card Task history: the audit trail, open.
20. A task change on the same page (route to another id) reads the new task.
