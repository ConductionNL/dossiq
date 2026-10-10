---
kind: code
depends_on: []
---

# Proposal: case-types-in-my-menu

Brings the dossiq sidebar and Nextcloud's personal settings to the boards
`DqZijbalk` and `DqPersoonlijkeInstellingen` (design-system#152, Ruben's
decision of 2026-10-09).

## Why

The sidebar named one case type by hand: the simple profile carried a fixed
`WooRequestsMenu` entry under Cases, and the backend `/api/manifest` delta added
every case type the user may see as a child of a `CasesGroup` the simple
profile does not have. A Woo officer and a permit officer saw the same list,
and on an instance with forty case types the full profile listed all forty.

The board draws it the other way round. Each employee picks the case types
they work on, in their own order, in a "Case types in my menu" section of
Nextcloud's own personal settings. The sidebar lists exactly those under a
heading "My case types", and a pencil beside the heading opens the settings.

## What changes

- A per-user, ordered list of case type ids, stored as the IConfig user value
  `menu_case_types` (JSON array) of app `dossiq`. IConfig user values are how
  this repo already keeps per-user preferences (PreferencesController,
  CaseAssistantService).
- `GET /api/menu-case-types` returns the chosen list and the case types the user
  may add; `PUT /api/menu-case-types` stores a new order. The server keeps only
  ids of case types the user may see, drops duplicates and caps the list at 30.
- `/api/manifest` answers with one menu entry per chosen case type, in the
  user's order, placed under the caption "My case types", each opening the
  Cases list filtered on that case type. The caption carries the link to the
  personal settings section (`href`), which the navigation renders as the
  pencil once nextcloud-vue draws a caption's `href`.
- The simple profile drops its hard-coded `WooRequestsMenu` entry and gains the
  `MyCaseTypesCaption` caption between Cases and Relations.
- Nextcloud's personal settings section "Dossiq" gains "Case types in my menu":
  the list in order with a move handle (drag, or the arrow keys on the focused
  handle) and a remove button per row, and a picker to add one.

## Decisions

- Default for a user who never saved a choice: the seeded Woo request case
  type (`3c0f5a00-0000-4000-a000-00000000a001`) when the user may see it,
  otherwise nothing. That is the one case type the menu named before, so no
  existing user loses an entry on upgrade. Saving an empty list is a choice and
  is kept (the menu then shows only the caption).
- The picker offers the current versions of the case types the user may see
  (OpenRegister RBAC, `supersededBy` empty). The board's hint says "the case
  types your team handles cases in"; dossiq has no team-to-case-type relation to
  read that from, so the hint says what is true: the case types you have access
  to.
- The open-case counts drawn on the board beside each case type are not
  built here: counting per case type is a query per row on every settings
  load. Left open.

## Impact

- `lib/Service/MenuCaseTypesService.php` (new), `lib/Controller/MenuCaseTypesController.php` (new), `lib/Controller/ManifestController.php`, `appinfo/routes.php`
- `src/menu-layout.simple.json`, `src/personalSettings.js`, `src/views/settings/MenuCaseTypesSettings.vue` (new), `templates/settings/personal.php`, `l10n/`
- Spec `case-type-navigation`: REQ-CTN-001 modified, REQ-CTN-004 and REQ-CTN-005 added.
- Change `simple-structure-profile` (open): its nine-entry scenario and its
  Woo requests scenario are amended to the board.
