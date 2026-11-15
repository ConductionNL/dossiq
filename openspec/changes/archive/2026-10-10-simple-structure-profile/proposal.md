---
kind: code
depends_on: []
---

# Proposal: simple-structure-profile

## Why

dossiq's menu holds 34 entries: 13 in the main list, 5 in the footer, 13 in
settings and 3 integrations. A case handler uses about nine of them on a working
day. The rest are set-up, logs and second doors to the same list, and they sit
between the handler and the work.

The Zuiddrecht design (`Vereenvoudiging`, 4 October 2026) proposes one shape for
every workplace app: at most ten entries in three groups, starting with
Dashboard, My work and the queue. Ruben decided on 5 October that this simple
structure is the new default, and that an administrator can bring the full
structure back. Nothing is deleted. Every page stays reachable.

dossiq is the pilot. pipelinq, decidiq and learniq copy the pattern, so the
mechanism has to be one they can lift: a second layout file, one setting, one
small module.

The same design review found a probable bug on the case page. Seven header
actions that write to the case did not show on a working case. This change
confirms it, fixes it and tests it.

## What changes

1. **A structure profile.** `src/menu-layout.simple.json` sits next to
   `src/menu-layout.json`. The app setting `menu_structure` (`simple` or `full`,
   default `simple`) picks one at boot. The page controller provides it as
   initial state, so the menu is right on the first render.
2. **The simple menu.** Start: Dashboard, My work, Team queue. Cases: All cases,
   Board, Tasks, Woo requests. Relations: Contacts, Organisations.
3. **Where the rest goes.** Deleted cases, Objects and Mail intake log move to
   settings. Your queue and Assigned to me are links on My work. Close out your
   day is a link on My work and on the dashboard. The footer keeps Reports,
   Documentation, Store and Features & roadmap.
4. **An admin choice.** Admin settings gain a section "Menu structure" with two
   options, Simple and Full.
5. **The archive gate.** The seven write actions on the case page are gated with
   `op: "empty"` instead of `eq null`, so they show on a case that is not
   archived.

## What does not change

- The full structure. `src/menu-layout.json` is untouched, and a spec asserts
  the full profile builds exactly what it built before.
- Pages and routes. Both profiles build the same 66 pages.
- The library. Everything here runs on `@conduction/nextcloud-vue` 2.57.1.

## What the library cannot express yet

`buildManifest` takes one layout with four keys. The simple structure needs
three things it has no word for. dossiq does them in `src/utils/structureProfile.js`
and names them here so they can move into the library:

1. **Order and label per profile.** A layout can move and remove entries. It
   cannot reorder or reword them. The profile's `menu` key is merged before the
   manifest's menu, and `buildManifest`'s own rule (the first definition of a
   key wins) does the rest.
2. **Captions and relocations together.** The relocation step ends by dropping
   every entry with no route, href, action or children. A caption is such an
   entry, so a layout with any `relocations` object loses its captions. The
   simple file carries no `relocations` key.
3. **Page differences per profile.** A fragment can replace a whole page. It
   cannot add one header action to a page. The profile's `pages` key holds
   overlays that replace config keys or append to a list.

Two more gaps are visible and not worked around:

- **Active state ignores `query`.** All cases and Woo requests both open the
  `Cases` page, and CnAppNav marks an entry active by route name alone. On the
  cases list both light up.
- **No help menu in the navigation.** The design moves the help links under
  "Hulp". CnAppNav has main, footer and settings, so the links stay in the
  footer.

## Impact

- Every instance shows the simple menu after the update. An administrator who
  wants the old menu sets Menu structure to Full, or runs
  `occ config:app:set dossiq menu_structure --value=full`.
- The e2e suite's CI instance is seeded on `full`, because most specs walk the
  full menu. The simple menu has its own spec.
