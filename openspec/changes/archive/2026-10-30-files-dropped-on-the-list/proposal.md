---
kind: code
depends_on: []
---

# Proposal: files-dropped-on-the-list

Brings the case Files tab to the boards `DqZaakDocumenten` and
`DqZaakDocumentenSlepen` (design-system#148, Ruben's decision of 2026-10-09).

## Why

The board draws three things the tab does not do. Adding files is a primary
button labelled "Bestanden toevoegen" ("Add files"), not an entry inside the
New menu. Dragging files over the documents list shows the drop state on the
list itself: a dashed frame and, centred, "Laat los om toe te voegen" ("Drop to
add"). And a hint line "Of sleep bestanden op deze lijst." ("Or drag files onto
this list.") sits under the list. The separate drop zone of the earlier board
is gone.

The tab is nextcloud-vue's `CnFilesBrowser` (through the `files` integration),
which already uploads a drop through the same DAV PUT as its picker. It had no
way to show the button, the overlay or the hint; nextcloud-vue change
`caption-edit-link-and-files-drop-state` adds them as three opt-ins,
`uploadButton`, `dropOverlay` and `dropHint`, with the board's words as
translated defaults.

## What changes

- `src/manifest.json`, the `case-files` widget of the case page: `props` gains
  `uploadButton: true`, `dropOverlay: true` and `dropHint: true`.
- Spec `case-dashboard-view`: REQ-CDV-22 added.

## Decisions

- The words come from the library (`Add files`, `Drop to add`, `Or drag files
  onto this list.`, with Dutch translations), not from dossiq. Manifest widget
  props are passed to the component as written and are not translated, so a
  label declared here would read English to a Dutch user.
- The drop target is not the only way in: the button stays, and the New menu
  keeps its upload entry. The overlay is decoration (`aria-hidden`); a polite
  live region says "Drop to add" while files are over the list and how many
  were added after the drop.
- The dashed drop zone of `CnFilesTab`'s fallback list (shown only when the
  case has no folder) is the library's and is left as it is.

## Impact

Inert until dossiq installs the nextcloud-vue release that carries the three
props: an unknown prop on `CnFilesTab` falls through as an attribute and
changes nothing.
