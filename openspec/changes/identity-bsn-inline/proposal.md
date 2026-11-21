# Proposal: identity-bsn-inline

## Why

Board DqContact draws "BSN *****7609 [Tonen]" on one line across the side card "Identiteit". In r29, live put the button
under the number, because the label column ("Geheimhouding") left the value too little room.

## What changes

The BSN row sets `inline: true` (nc-vue `feat/facts-row-inline`): the label, the masked number and "Tonen" share one line.

## Scope

Config only (`src/manifest.json`).
