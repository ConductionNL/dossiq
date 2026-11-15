# Task forms keep a draft

## Why

Row 3.14 (intermediate save of task forms). nextcloud-vue shipped the library half in `form-dialog-autosave` (all tasks ticked): `CnFormDialog` keeps what a user typed when the dialog closes or the tab is lost, offers it back on reopen, and with `draftField` stores the object with a draft marker instead of validating it. dossiq never passes those props, so a handler who loses the tab halfway through a long task form starts over. Measured on development `a5c9b4153`: `draftField` appears nowhere in `src/manifest.json` or `src/`.

## What changes

- The task form on the task page (`TaskDetail`) and the case edit form on `CaseDetail` pass `draftField` and `draftAppId: "dossiq"`, so their input survives a closed dialog and can be saved as a draft.
- A saved draft is the destination object in status draft (decision 180), labelled "Voorlopig" (decision 200); a draft never blocks the task's own deadline.

## Impact

- `src/manifest.json` pages `TaskDetail` and `CaseDetail` (form dialog config); no PHP change.
- Spec: one requirement added to `task-management`.
- Row 3.14 moves to built once the live check passes.
