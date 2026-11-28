# task-page Specification

## Purpose
What dossiq does for task page. The requirements were first written by the change `task-history-stacked` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-task-history-stacked/`) and cover: The task's side history puts the moment under the action.

## Requirements

### Requirement: The task's side history puts the moment under the action

The task page's side history SHALL show each entry's moment and actor under its action.

#### Scenario: DqTaak

- **THEN** the row reads "Taak opgepakt door Pieter Jansen" with "6 okt 09:05" under it

### Requirement: The task's case card names the case handler

The Case card on a task page SHALL name the case's handler by display name.

#### Scenario: DqTaak

- **GIVEN** a task on a case handled by Pieter Jansen
- **THEN** the Case card reads "Behandelaar Pieter Jansen · termijn van de zaak ..."

### Requirement: The task's description is in the side Task card

The task page SHALL show the task's description as the first line of the side Task card, and SHALL NOT draw a
separate description card in the Overview.

#### Scenario: DqTaak

- **GIVEN** "Hoorzitting inplannen" with the description "Plan de hoorzitting met de indiener en de commissie."
- **THEN** the Overview starts with Checklist
- **AND** the Taak card opens with that description

### Requirement: The task page's side history reads in words

The side "Historie" card on the task page SHALL name each engine action in the same words as the task's history
tab, with the actor where the action has one.

#### Scenario: DqTaak

- **GIVEN** a task that Pieter Jansen picked up
- **THEN** the side Historie card reads "Taak opgepakt door Pieter Jansen", not `claim (active)`

### Requirement: The task page header names its case and deadline

The task page's header line SHALL name the number of the case the task belongs to and the task's deadline, the
deadline as today, tomorrow or yesterday when it is one of those and as a short date otherwise.

#### Scenario: DqTaak

- **GIVEN** the task "Hoorzitting inplannen" on case 2026-0074, due today
- **THEN** the header line reads "bij zaak 2026-0074, deadline vandaag"

### Requirement: A task says which step its case waits to move to

A task that a flow run waits on SHALL say which status its case moves to once the task is done (the case type's next
status) and since when the flow has waited on it.

#### Scenario: DqTaak

- **GIVEN** "Hoorzitting inplannen" on an objection case in the status before "Beslissing op bezwaar"
- **THEN** the card reads "De zaak gaat pas naar Beslissing op bezwaar als deze taak klaar is."
