# Proposal: case-type-history-compact-rows

## Why

Board DqZaaktype draws the side card Historie with rows 10px above and below ("Versie 4 gepubliceerd" over "Lars de
Koning · 2 okt", 56px). The board look pads a stacked history row 12px, as DqTaak draws it, so r34 read the row h 56 > 60.

## What changes

- The case type side card Historie sets `content.density: "compact"`. Needs nextcloud-vue
  fix/r34-audit-card-compact-stacked-rows (the density on the audit card); without it the key is ignored.
