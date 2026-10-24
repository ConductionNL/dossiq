# Proposal: task-list-titles-wrap

## Why

Board DqTaken draws a task's title and its subject in full, over two or three lines ("Bezwaarschrift aanvullen bij
de indiener"), rows about 99px high. The list cut both to one line ("Documenten be...", "Verlichting fietspad ...").

## What changes

The simple profile's Tasks list sets `cn-cell--wrap` on its Task and Subject columns. In the board look this needs
nextcloud-vue fix/board-index-wrap-cell (24t1g); before that release the cells stay on one line, as now.

## Scope

Config only (`src/menu-layout.simple.json`).
