# Proposal: task-header-case-and-deadline

## Why

Board DqTaak's header line reads "Taken / Hoorzitting inplannen · bij zaak 2026-0074, deadline vandaag". The page
showed only the due wording ("Over tijd"), without the case the task belongs to.

## What changes

TaskDetail `headerMeta` becomes "at case {subject.identifier}, deadline {dueAt|relative}" (nl "bij zaak ...,
deadline ..."). `subject.identifier` comes from openregister feat/task-subject-identifier; `|relative` says today,
tomorrow, yesterday, else the short date. A token without a value drops itself.

## Scope

Config (`src/manifest.json`) and one translation key.
