---
kind: code
---

# Proposal: r4-tour-menu-labels-and-settings-styles

## Summary

Two things the round-3 cloud check found in dossiq.

1. The getting-started tour says "Click Cases in the menu". The menu item it
   points at is called "All cases" in both menu structures. In the simple
   structure "Cases" is a caption, and clicking a caption does nothing. Case
   types and Flows sit under Advanced in both structures, and the tour said
   "Open Case types in the menu" as if they were in the main list.
2. On Settings > Personal > Dossiq the notification table reads "Notifications
   Bundle these" as one header and the scope picker squeezes its label. The
   personal settings bundle never loaded the library stylesheet, so every
   library component on that page rendered without its own CSS. The admin
   email settings bundle had the same gap.

## What changes

- Tour tasks name the real menu labels, in English and Dutch: "Click All cases
  in the menu", "Open Advanced, then Case types", "Open Advanced, then Flows".
- A unit test reads the tour and both menu structures, and fails when a
  nav-item step's task does not name the label its target carries.
- `src/personalSettings.js` and `src/emailSettings.js` import
  `@conduction/nextcloud-vue/css/index.css`, as `src/main.js` already does. A
  unit test fails when an entry that imports the library leaves the
  stylesheet out.
- The scope picker gets room for its label.

## Out of scope

The settings and dashboard-widget entries that do not import the library
directly. The Credentials text is library copy and is fixed in
@conduction/nextcloud-vue.
