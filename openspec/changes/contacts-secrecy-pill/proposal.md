# Proposal: contacts-secrecy-pill

## Why

Board DqContacten draws "Geheimhouding" beside the BSN of a resident whose data is protected (`indicatieGeheim`), so a
handler sees it in the list before opening the person.

## What changes

The Contacts BSN column declares a warning pill "Data protection" (nl "Geheimhouding") for `indicatieGeheim: true`.
Needs nextcloud-vue feat/column-cell-pills; on an older release the key is ignored and the list reads as now. Stacked
on r25/contacts-column-widths (same column).

## Scope

Config only (`src/manifest.json`); existing key "Data protection".
