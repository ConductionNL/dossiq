# Proposal: task-history-stacked

## Why

Board DqTaak's Historie card reads "Taak opgepakt door Pieter Jansen" with "6 okt 09:05" under it. Live drew the
date to the right of the action and the user id under it.

## What changes

The side history card is `layout: "stacked"` (nextcloud-vue feat/audit-card-stacked-rows, which also names the
person). Config only.
