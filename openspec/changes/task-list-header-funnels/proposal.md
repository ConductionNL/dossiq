# Proposal: task-list-header-funnels

## Why

Board DqTaken draws a filter funnel in the Onderwerp, Status, Prioriteit and Deadline headers, the Deadline one
marked active by the month's default filter. The task list draws none.

## What changes

The Tasks list's Subject column filters on `objectUuid` and its Priority column on `priority` (`filterField`), the
task inbox's own argument names that the sidebar's search fields already declare. With nextcloud-vue
feat/named-source-header-filters, a named source with `headerFilters: "searchFields"` gives such a column a funnel
built from that search field; Status (`state`) and Deadline (`dueAt`) match by their key. Task and Handler have no
search field behind them and get no funnel.

## Scope

Config only (`src/menu-layout.simple.json`). Inert on a nextcloud-vue without the header filters.
