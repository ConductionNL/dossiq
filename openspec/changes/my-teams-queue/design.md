# Design: my-teams-queue

## Filter

`{ "assignedGroup": "@myGroups", "assignee": "IS NULL", ...the Queue page's conditions }`.

`@myGroups` resolves in the browser to the reader's group ids, and
`CnObjectListWidget` sends an array as repeated `assignedGroup[]=` params,
which OpenRegister's `MagicSearchHandler` reads as `assignedGroup IN (...)`.

While the groups load, and for a reader in no group, the token stays
unresolved and the list shows its `prompt` instead of fetching. That matters:
OpenRegister adds no condition for an empty IN list, so sending one would show
every team's queue to a person in no team. The prompt therefore reads as a
neutral line ("Cases waiting for your teams show here") rather than an error,
because it also shows for the moment the groups are loading.

The My team view's copy stays equal to the Dashboard preset
(`tests/vitest/landingViews.spec.js`), and both now equal the Queue page's
filter plus `assignedGroup: @myGroups`.

## Team column

`widget: group` names the library's built-in group cell. The library would
pick it on its own from `referenceType: nextcloud-group`; naming it keeps the
intent in the manifest. Against an older library the unknown widget falls back
to the plain value, the id, which is what the column showed before.
