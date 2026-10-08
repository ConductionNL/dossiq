## ADDED Requirements

### Requirement: REQ-TASK-020 You set a reminder for a colleague from the case

`#CaseDetail` SHALL offer Remind, a form asking who, when and what, that
creates an engine task on the case with that assignee, due date and title
and `kind: reminder`. The task SHALL appear on Tasks, on My work of the
assignee and on the case's Work tab, and the assignee SHALL receive the
platform's assignment notification.

#### Scenario: A reminder reaches a colleague
@e2e tests/e2e/case-reminder.spec.ts

- **GIVEN** an open case and a colleague Anna
- **WHEN** you press Remind, pick Anna, the 3rd and "Call the applicant", and save
- **THEN** a task "Call the applicant" due the 3rd assigned to Anna SHALL exist on the case
- **AND** Anna SHALL see it under Mine on Tasks

#### Scenario: A reminder is closed like a task
@e2e tests/e2e/case-reminder.spec.ts

- **GIVEN** a reminder task on a case
- **WHEN** you complete it on the Work tab
- **THEN** it SHALL leave the open tasks of the case
