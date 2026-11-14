# Proposal: organisations-icon-tile

## Why

Board DqOrganisaties draws each organisation's building icon on a 32px teal tile, 12px before the name. Live drew a
bare grey 20px icon.

## What changes

The Organisations page sets `rowIconTone: "teal"`. nextcloud-vue feat/board-row-icon-tile (24t1m) draws the tile;
an older nextcloud-vue ignores the key. Config only. Stacked on r25/organisations-legal-form-short.
