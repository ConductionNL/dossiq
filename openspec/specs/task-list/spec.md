# task-list Specification

## Purpose
What dossiq does for task list. The requirements were first written by the change `tasks-board-column-widths` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-tasks-board-column-widths/`) and cover: The task list columns take the board's widths.

## Requirements

### Requirement: The task list columns take the board's widths

The task list SHALL draw Taak at 150px, Onderwerp at 168px, Status at 185px, Prioriteit at 133px and Deadline at 133px.
The handler column SHALL take the rest.

#### Scenario: DqTaken

- **THEN** "Prioriteit" starts 563px after the title column, as on the board

### Requirement: The task list names a state as the task page does

The Tasks list SHALL show a task's state in the words the task page's state pill uses, and SHALL colour it by the
state, not by the shown words: ready to claim blue, in execution purple, completed green, terminated red.

#### Scenario: DqTaken

- **GIVEN** an active task in the Tasks list, in Dutch
- **THEN** its Status pill reads "In uitvoering" in purple, as the pill on its task page

### Requirement: The task list sorts by deadline and priority

The Tasks list SHALL open sorted on the deadline, soonest first, and SHALL let the reader sort it by deadline or by
priority from the column headers, over the whole inbox rather than the page on screen.

#### Scenario: DqTaken

- **GIVEN** the Tasks list
- **THEN** the overdue task comes first and the Deadline header shows an ascending sort

### Requirement: The task list names the team of an unclaimed task

The Tasks list SHALL show, for a task nobody holds, the team it is offered to in the Behandelaar column, as
"<team>, niet geclaimd" with the team's initials.

#### Scenario: DqTaken

- **GIVEN** an unclaimed task offered to the group "Team Woo"
- **THEN** its Behandelaar cell reads "TW  Team Woo, niet geclaimd"

### Requirement: The task list links a task's case with its number

The Tasks list SHALL show the subject of a dossiq task as a link to that case's page, with the case number on a
muted line under it. A task of another app SHALL show its subject as text.

#### Scenario: DqTaken

- **GIVEN** the task "Hoorzitting inplannen" on case 2026-0074 "Bezwaar parkeerboete Havenstraat"
- **THEN** its Subject cell links "Bezwaar parkeerboete Havenstraat" to the case page, with "2026-0074" under it

### Requirement: The task list shows a title and subject in full

The Tasks list SHALL show a task's title and its subject in full, wrapping over lines within their columns, rather
than cutting them with an ellipsis.

#### Scenario: DqTaken

- **GIVEN** a task "Bezwaarschrift aanvullen bij de indiener" in the Tasks list
- **THEN** its title reads in full over three lines

### Requirement: The task list draws a deadline in its urgency

The Tasks list SHALL draw an open task's deadline in red when it is past or today, with a red edge on the row; in
orange when it is tomorrow or the day after; by weekday and date within the coming week; and as the short date after
that. A finished task's deadline SHALL read "Klaar op <date>" in grey, without the edge.

#### Scenario: DqTaken

- **GIVEN** today is Wednesday 7 October
- **THEN** an open task due on the 9th reads "Vrijdag 9 okt" in orange, and a task finished with deadline the 6th
  reads "Klaar op 6 okt" in grey

### Requirement: The task list filters from its column headers

The task list's Subject, Status, Priority and Deadline columns SHALL filter on the task inbox's search fields
(`objectUuid`, `state`, `priority`, `dueAt`), so their headers carry a funnel that writes the sidebar's filter.

#### Scenario: DqTaken

- **GIVEN** the task list with the month's deadline filter
- **THEN** Onderwerp, Status, Prioriteit and Deadline carry a funnel, the Deadline one active
- **AND** Taak and Behandelaar carry none
