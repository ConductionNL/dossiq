# Case views shared with a team

## Why

Row 9.4 (shared saved searches with department or role permissions). Both halves of the platform are done: openregister `view-group-share` (archived 2026-09-30) stores `View.sharedWith` with owner-or-admin guards, and nextcloud-vue `saved-views-shared-by-role` (all tasks ticked) gives `CnSaveViewDialog` a `sharedWith` group picker and lists shared views in `CnSavedViewsControl`. dossiq's lists (`allowSavedViews: true` on the cases, tasks and queue pages in `src/manifest.json`) take that dialog from the library, so the share should already appear, but nobody has checked it on dossiq, and the department groups a handler picks from are dossiq's organisation roles, not every Nextcloud group.

## What changes

- The save-view dialog on dossiq's lists offers the reader's own handling teams and department groups as share targets (organisation roles with a group, `example-set-department-tree`), read-only or editable.
- A view shared with a team shows under "Gedeeld met mijn team" in the saved views control, with its columns and label.

## Impact

- `src/manifest.json` list pages with `allowSavedViews` (a `savedViewShareGroups` source if the library needs one), no PHP change unless the group source needs an endpoint.
- Spec: one requirement added to `case-search-and-lists`.
