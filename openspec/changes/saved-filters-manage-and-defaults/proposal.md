# Manage saved views and the views dossiq ships

## Why

Boards DqFilterBeheren, DqFilterBewerken, DqFilterVerwijderen, DqFilterInMenu and DqZakenSorteren draw what you can do with a saved view. No requirement described it. Their capability token `saved-filters-manage-and-defaults` resolved to no spec (decision 317).

## What Changes

- Rename a saved view, change its description and icon, and keep or replace its criteria.
- Delete a saved view after a confirmation that says what goes and what stays.
- Save the sort order with a view, from the side panel.
- Keep the views dossiq ships read-only, and copy one to make it your own.
- Pick the views that stand in the left menu.

The boards decide the scope. They draw no control to reorder the list of views, and no "open on this view" choice. This change adds neither.

Sharing a view with a team stays in `case-views-shared-with-a-team`.

## Impact

`src/manifest.json` list pages with `allowSavedViews`, and the saved views control in nextcloud-vue where it lacks one of these actions. OpenRegister's `View` already stores the criteria and the shares.
