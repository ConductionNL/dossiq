# Proposal: tasks-board-column-widths

## Why

The r31 strict verdict on DqTaken reads the "Prioriteit" and "Deadline" headings at other places than the board. Board
DqTaken draws the columns Taak 150, Onderwerp 168, Status 185, Prioriteit 133 and Deadline 133, and the handler takes the
rest. Live gave Taak 150 and Onderwerp 170, and split the rest evenly (168 each), so Prioriteit started at 841 against 856
and Deadline at 1009 against 989. These are measured: the board rendered locally, live through the headless probe.

## What changes

The task list's columns take the board's widths.

## Scope

Config only (`src/menu-layout.simple.json`).
