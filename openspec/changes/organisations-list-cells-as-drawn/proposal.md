# Proposal: organisations-list-cells-as-drawn

## Why

Board DqOrganisaties wraps a long trade name under itself ("Aannemersbedrijf / Van Wijk B.V.") and reads an
organisation without open cases as "Geen", as it does for open tickets. The list cut the name ("Regional Stimflex
Coöpe...") and showed a "0" badge for open cases.

## What changes

The Organisations Name column sets `cn-cell--wrap` (wraps in the board look with nextcloud-vue
fix/board-index-wrap-cell, 24t1g) and the Open cases aggregate gets `zeroLabel: "None"`, as Open tickets has.

## Scope

Config only (`src/manifest.json`, Organisations columns); existing key "None".
