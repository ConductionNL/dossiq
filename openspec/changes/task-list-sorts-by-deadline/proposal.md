# Proposal: task-list-sorts-by-deadline

## Why

Board DqTaken opens "gesorteerd op deadline" with the Deadline header sorted ascending, the soonest deadline first,
and draws sortable headers. The inbox came back latest deadline first, and no header sorted.

## What changes

The simple profile's Tasks list sets `sortKey: "dueAt"`, `sortOrder: "asc"` and marks its Priority and Deadline
columns sortable. The inbox sorts these server side through nextcloud-vue feat/tasks-source-sorts-on-server (24t1g).
Merge together with it: on an older nextcloud-vue the Deadline header would show the sort while the inbox keeps its
own order. Stacked on r25/tasks-pooled-handler (same column block).

## Scope

Config only (`src/menu-layout.simple.json`).
