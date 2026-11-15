# Proposal: case-type-lists-names-as-links

## Why

Board DqZaaktypeStatussen names its statuses and results as links since design-system #609 (27 Oct): 14px in the
link colour, no underline, a status with its colour dot. The tables still asked for `rowTitle: "strong"` (25 Oct).
nc-vue 2.164.0 draws "strong" as 600 ink, so r40 measured both tables' names in the main ink against the board's link
colour.

## What changes

- The Statussen and Resultaten tables drop `rowTitle: "strong"` and name their rows with the default link title.
- The status colour dot and the sentence-case archival action stay.
