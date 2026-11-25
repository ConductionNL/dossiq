# Proposal: task-list-pooled-handler

## Why

Board DqTaken shows a task nobody has claimed with its team in the Behandelaar column: "TW  Team Woo, niet geclaimd".
The list showed a dash.

## What changes

The simple profile's Tasks Behandelaar column sets `groupField: "candidateGroups"` and `groupLabel: "{group},
unclaimed"` (nl "{group}, niet geclaimd"). Needs nextcloud-vue feat/avatar-cell-group-fallback (24t1g); before that
release the cell shows a dash, as now. Stacked on r25/tasks-deadline-tones (neighbouring lines).

## Scope

Config (`src/menu-layout.simple.json`) and one translation key.
