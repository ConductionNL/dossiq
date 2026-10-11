# Design: case-page-dutch-labels-one-history-tour-first

## Translate the override labels in the existing pass

dossiq already translates the manifest keys the library draws as written, once,
before the renderer (`translateLensLabels.js`, wired in main.js). An override
label is the same case, so it joins that pass. The walk covers every override
map (`fieldOverrides` on a page or a header action, `overrides` on a data
widget) instead of naming the seven places the requester override sits today.

## One heading on the term card

In the simple profile the term tile is the first card of the side column. The
card wrapper already shows the widget title, translated, so the countdown's own
label would print "Termijn" twice. The profile patches the widget's content
without the label. In the full profile the tile is in the KPI row with its title
hidden, so there the label stays and is translated.

## Rename, do not remove, the sidebar trail

DqZaakHistorie keeps a history tab in the strip and points to the full audit
trail in the sidebar. Removing the sidebar tab would take the change history
off the page, which row A05 and REQ-TL-10 keep there. So the trail gets its own
name, on the case page only; the other detail pages keep History because they
have no history in a tab strip.

## Tour first, support note later

CnAppRoot reads `supportDialog` once, at setup, so the decision is made before
the app mounts, from initial state. A BeforeTemplateRenderedEvent listener (the
page controller sits on the coupling ceiling, as with the Woo reports state)
reads the preference
nextcloud-vue writes when the tour is completed or skipped
(`walkthrough_completed_version`, the manifest's `completionConfigKey`). While it
is empty, App.vue passes `supportDialog: false`, so the library neither shows
the note nor records it as seen. The first load after the tour shows it, once,
as before. A page served without the state keeps the library default.
