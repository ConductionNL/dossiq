# Design: case-types-in-my-menu

## Storage

IConfig user value, app `dossiq`, key `menu_case_types`, value a JSON array of
case type uuids in menu order. Unset (`''`) means "never chose" and yields the
default; `[]` means "chose nothing". The generic `PreferencesController` is not
used for writing because it stores any string: the dedicated endpoint validates
the ids against the case types the user may see, so a stale or foreign id never
reaches the menu.

## Which case types are offered

Amended by `case-type-handling-teams`. `MenuCaseTypesService::offeredCaseTypes()`
narrows the visible case types (below) to those whose handling teams
(`CaseTypeHandling::teams()`: the default group plus `handling.teams`) include
a Nextcloud group the user is in. When that leaves nothing, because the user is
in none of those groups, it offers every visible case type. The offered list
replaces the visible one for the picker's `available`, for reading `chosen` and
for cleaning on save.

## Reading the case types

`MenuCaseTypesService::visibleCaseTypes()` searches the configured
`register` / `case_type_schema` under the user session (OpenRegister RBAC), keeps
the rows without `supersededBy` (current versions), and returns `{id, title}`
sorted by title. The chosen list is intersected with it on every read, so a case
type that is retired or no longer visible drops out of the menu without a
write.

## The menu delta

`/api/manifest` returns

```json
{"menu": [
  {"id": "MyCaseTypesCaption", "type": "caption", "label": "<My case types, translated>", "order": 30, "href": "/index.php/settings/user/dossiq"},
  {"id": "ct-<uuid>", "label": "<title>", "icon": "FolderOutline", "route": "Cases", "query": {"caseType": "<uuid>"}, "order": 31}
]}
```

The caption is also declared in `menu-layout.simple.json` (order 30, between
Tasks at 26 and the Relations caption), so the simple menu has its heading on
the first paint, before the delta lands, and keeps it when the user chose
nothing: the pencil is how they find the setting. The delta repeats the caption
whole (label translated server-side) so it also stands in the full profile,
where the layout declares no such entry and a label-less entry would fail the
schema. Every key it sends is one the manifest schema already allows on a menu
item (`href` included), so the merged manifest still validates against the
installed library; a delta that fails validation is discarded whole.

Order band: the manifest schema takes integer orders. The caption is order 30,
the entries take 31 upward in the user's order (31 to 60, the list is capped at
30), and the Relations caption, Contacts and Organisations move from 40/42/44 to
80/82/84 so all thirty fit between.

The full profile keeps its Cases folder pane and its groups; the caption and
the entries land in it by order. That is accepted: the full profile is the
administrator's fallback, the board draws the simple one. The `CasesGroup`
children of every case type are no longer sent in either profile.

## The pencil

nextcloud-vue's CnAppNav renders a caption from `label` only today. Drawing a
caption's `href` as a pencil link (`NcAppNavigationCaption` `#actions`) is a
library change, proposed in nextcloud-vue alongside this change. Until it ships,
the `href` is inert and the section stays reachable from the user menu,
Personal settings, Dossiq.

## Personal settings

The section is a fifth mount in the existing `PersonalSettings` form of the
existing `dossiq` personal section, the Nextcloud way (ISettings personal +
IIconSection), not an app route. It reads and writes `/api/menu-case-types` and
saves on every change ("Changes are saved right away", as the board says).

Reordering: the handle is a button. Mouse users drag a row by it (HTML5 drag
and drop); keyboard users focus it and press Arrow up or Arrow down. Its
accessible name says where the row is ("Woo requests, move, now 1 of 3"), and a
polite live region announces the new position after a move.
