# Proposal: views-in-my-menu-settings

## Why

Board DqPersoonlijkeInstellingen (design-system #305) opens the personal settings with "Weergaven in mijn menu": the
saved views pinned under My views, reordered by dragging or the arrow keys, taken out with "Uit menu halen", and a
"Weergave toevoegen" picker over the views the user can see. dossiq dropped its case type picker when pinned case types
became views (2bf2f16d7), and had nothing in its place.

## What changes

The dialog draws the library's `views` section (nextcloud-vue `CnSavedViewMenuSettings`, branch
`feat/saved-view-menu-settings`) first, before dossiq's own sections. A library without the key ignores it.
