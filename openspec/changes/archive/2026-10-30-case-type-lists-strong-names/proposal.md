# Proposal: case-type-lists-strong-names

## Why

DqZaaktypeStatussen draws status and result names 14px at 600 without an underline, a status with its list colour as a
dot before the name, and archival actions in sentence case ("Vernietigen"). Live drew underlined 15px links, no dot,
and the stored codes ("vernietigen"). Decided 25 Oct (coordinator).

## What changes

- Statussen table: `rowTitle: "strong"`; the name column a swatch whose dot reads the status colour through the
  palette's swatches (the same map the status form uses).
- Resultaten table: `rowTitle: "strong"`; the archival action column labels Retain / Destroy / Retain permanently
  (nl Bewaren / Vernietigen / Blijvend bewaren).
- Needs nc-vue feat/table-strong-title-and-swatch-map; before it `strong` falls back to the link title and the dot
  shows only for CSS-valid names.
- Resultaten keeps its row actions (Delete), which users have today: the board is amended to draw them.
