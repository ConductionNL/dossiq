# example-set-pieters-work

## ADDED Requirements

### Requirement: The example data carries the cases and tasks the work boards draw

The Zuiddrecht example set SHALL hold the cases "Bezwaar parkeerboete Havenstraat" (Bezwaar), "Parkeervergunningen binnenstad" and "Woo-verzoek vergunningen evenementen" (Woo-verzoek) and "Kapvergunning essen Parkweg" (omgevingsvergunning). It SHALL hold six tasks on them: "Hoorzitting inplannen" on the objection, and tasks covering the priorities low, normal, high and urgent, one completed task and one task offered to a group without an assignee.

#### Scenario: Pieter opens his task list
- **GIVEN** the set is installed
- **WHEN** Pieter opens Tasks
- **THEN** the list SHALL show his tasks with an urgent one overdue, the hearing due today, and one task done
