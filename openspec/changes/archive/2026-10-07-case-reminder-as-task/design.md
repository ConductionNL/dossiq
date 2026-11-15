# Design: case-reminder-as-task

## D-1. A reminder is a task with a kind

The handler posts to `/api/flow-tasks` with `subject` the case, `assignee`,
`dueDate`, `title` and `kind: reminder`. The kind is a label the Tasks index
can facet on; nothing else treats it specially.

## D-2. The form asks three things

Who (a user picker, default you), when (a date, default tomorrow), what (a
line of text). The dialog lives in `src/dialogs/RemindDialog.vue`.

## D-3. Done is done

Completing the reminder is completing the task, on the Work tab or on Tasks.
No separate state.
