# task-list Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-list-deadline-tones](../../)

## ADDED Requirements

### Requirement: The task list draws a deadline in its urgency

The Tasks list SHALL draw an open task's deadline in red when it is past or today, with a red edge on the row; in
orange when it is tomorrow or the day after; by weekday and date within the coming week; and as the short date after
that. A finished task's deadline SHALL read "Klaar op <date>" in grey, without the edge.

#### Scenario: DqTaken

- **GIVEN** today is Wednesday 7 October
- **THEN** an open task due on the 9th reads "Vrijdag 9 okt" in orange, and a task finished with deadline the 6th
  reads "Klaar op 6 okt" in grey
