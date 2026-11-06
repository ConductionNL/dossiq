# example-set-field-task-contact Specification

## Purpose
What dossiq does for example set field task contact. The requirements were first written by the change `example-set-field-task-contact` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-field-task-contact/`) and cover: The example data carries what the inspection, task and contact boards draw.

## Requirements

### Requirement: The example data carries what the inspection, task and contact boards draw

The Zuiddrecht example set SHALL give Pieter Jansen four field inspections scheduled on the install day, each on a case of the set, with the statuses `synced`, `in_progress`, `planned` and `planned`. It SHALL give him one task in execution with priority `high` and a checklist of four items, two of them checked. It SHALL hold four contact moments on Henk Bakker's parking permit case, inbound and outbound and over more than one channel, each naming Henk and the employee who handled it.

#### Scenario: Pieter opens the field inspection page
- **GIVEN** the set is installed today
- **WHEN** Pieter opens the field inspection page
- **THEN** the Today frame SHALL list four inspections, the first done and the second under way

#### Scenario: Pieter opens his task
- **GIVEN** the set is installed
- **WHEN** Pieter opens the task "Hoorzitting inplannen"
- **THEN** the page SHALL show priority high and the checklist with two of four items done
