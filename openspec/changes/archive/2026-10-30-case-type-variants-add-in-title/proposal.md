# Proposal: case-type-variants-add-in-title

## Why

Board DqZaaktype draws "Variant toevoegen" beside the Varianten card title; live drew it inside the card body under
the subtitle (COMPDIFF r24: Varianten `header.actions 1>0`, first row inset 633).

## What changes

`case-type-variants` sets `add.placement: "title"` (nextcloud-vue `feat/field-list-add-in-card-title`). Until that
release the library reads an unknown placement as the footer, so the button sits under the variants.

## Reach

Config only.
