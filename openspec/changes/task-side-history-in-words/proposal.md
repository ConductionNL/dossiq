# Proposal: task-side-history-in-words

## Why

Board DqTaak draws the task's side "Historie" card in words: "Taak opgepakt door Pieter Jansen", "Taak aangemaakt".
The history tab already says so through `actionLabels`, but the side card is a second audit-trail widget without
them, so it showed the engine's raw `create (active)`.

## What changes

The TaskDetail side "Historie" card carries the same `actionLabels` as the history tab.

## Scope

Config only (`src/manifest.json`, TaskDetail `side-history`); existing translation keys.
