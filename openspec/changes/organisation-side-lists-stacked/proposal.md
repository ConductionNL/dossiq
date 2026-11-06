# Proposal: organisation-side-lists-stacked

## Why

Board DqOrganisatie draws the "Lopende zaken" and "Open tickets" side cards as a short list: the row 10px under the title,
with no rule and no row padding. Live put the case 17.7px down (r26a), behind a side hairline and 10px row padding.

## What changes

Both side lists set `density: "stacked"`. With nc-vue `fix/board-side-lead-gaps`, a stacked side list in the board look
starts 10px under its title, with no hairline and no row padding.

## Scope

Config only (`src/manifest.json`).
