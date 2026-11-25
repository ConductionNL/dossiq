# Proposal: task-list-states-in-task-words

## Why

Board DqTaken draws a task being worked on as "In uitvoering", the word the task page's own pill uses (DqTaak).
The task list read the state through the library's `stateLabel`, which says "In behandeling" ("In progress"), so
the same task had two names one click apart. Its tones were keyed on the shown words in two languages.

## What changes

The simple profile's Tasks list declares its Status column on the task's `state` with the words of the task page
(`enum` + `enumLabels`: In execution / Ready to claim / Completed ...) and keys the tones on the state itself.

## Scope

Config only (`src/menu-layout.simple.json`); existing translation keys.
