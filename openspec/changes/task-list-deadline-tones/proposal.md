# Proposal: task-list-deadline-tones

## Why

Board DqTaken draws a deadline in its urgency: "2 dagen te laat" and "Vandaag" in red with a red edge on the row,
"Morgen" and "Vrijdag 9 okt" in orange, "14 okt" plain, and a finished task's deadline as "Klaar op 6 okt" in grey.
The list drew overdue red and every other date plain, without a row edge.

## What changes

The simple profile's Tasks Deadline column sets the due options `todayTone: "danger"`, `soonWithin: 2`,
`nearWithin: 6`, `activeWhen` (open tasks) and `inactiveLabel: "Done on {date}"`; the page's `rowClass` marks an
open task due today or earlier `cn-row--overdue`. Needs nextcloud-vue feat/due-text-today-near-done and
fix/board-row-overdue-edge (24t1g); before that release the options are ignored and the list reads as now.

## Scope

Config (`src/menu-layout.simple.json`) and one translation key.
