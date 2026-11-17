# Pinned case types are views

## Why

Ruben, 19 October 2026: "Mijn zaaktypen" leaves dossiq's menu. The library
adds a standard "My views" section at the bottom of every app menu, holding
the views of a list a user added to it. A case type a user pinned is a view of
the case list (the case list filtered on it), so it belongs there.

## What changes

- The `/api/manifest` delta no longer adds a caption and menu entries. It adds
  one default view of the Cases page per case type the user pinned
  (`case-type-<uuid>`, the case type with the working-list rule); the Woo
  request case type keeps the view the page ships (`woo-requests`).
- A one-time repair step (`PinCaseTypesAsViews`) adds each user's pins to their
  menu views preference (`cn_saved_view_menu`) as
  `{ id: 'default:<slug>', page: 'Cases', name }`, after the views they added
  themselves, once per user (a view taken out later stays out). A user who
  never chose gets the Woo view, the entry they saw. `menu_case_types` is kept.
- The simple profile drops `MyCaseTypesCaption`; the settings dialog drops the
  case type picker, since a view is added to the menu from the list.

## Depends on

- nextcloud-vue #1792 (default views on a page, `?savedView=`), renamed to
  views, and COMP-2's "My views" menu section (`cn_saved_view_menu`).
- The library resolving `default:<slug>` against defaults the manifest delta
  supplies, and merging a page's `defaultSavedViews` from the delta by slug
  (a non-keyed array in the delta replaces the page's own today).
- dossiq #4094 and the Woo view (feat/screens-r16-woo-as-saved-filter).

## Kept for now

`MenuCaseTypesController` (`/api/menu-case-types`) and the list it writes stay:
the delta still reads `menu_case_types`. Whether a user can still edit that
list without the picker is for the library's "add to menu" to answer.
