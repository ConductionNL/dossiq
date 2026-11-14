# Proposal: case-type-messages-data-table

## Why

r25b on :8080: DqZaaktype "Berichten aan de aanvrager" draws as the compact grid (13px/400 header, no inset, 25px rows) and
its opt-out note as plain text; the board draws the card table and a blue info box.

## What changes

`case-type-messages` sets `tableLook: "data"` and `footnoteVariant: "info"`. Needs nextcloud-vue
`feat/record-list-data-table-and-notice-footnote`; an older library ignores both keys.

## Reach

Config only.
