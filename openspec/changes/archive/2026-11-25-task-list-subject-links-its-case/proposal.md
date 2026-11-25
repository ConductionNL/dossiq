# Proposal: task-list-subject-links-its-case

## Why

Board DqTaken draws a task's subject as a link to its case, with the case number under it ("Bezwaar parkeerboete
Havenstraat" over "2026-0074"). The list showed the subject as plain text without a number.

## What changes

The simple profile's Tasks Subject column becomes a link to CaseDetail for a dossiq task (`appId` dossiq, the case
being `objectUuid`); another app's task keeps its subject as text. Its second line is `subject.identifier`, which
openregister's task subject context carries from openregister feat/task-subject-identifier on. Without that, the
second line is left out.

## Scope

Config only (`src/menu-layout.simple.json`). Stacked on r25/tasks-title-subject-wrap (same column).
