## ADDED Requirements

### Requirement: REQ-CSD-06 A boolean a register fragment adds declares its control

Every boolean property a register fragment adds to the case schema SHALL
declare `inputControl: boolean` and SHALL NOT declare a `matchType`, the
same as the booleans of the case schema itself.

#### Scenario: The DSO overdue flag is filterable

- GIVEN the DSO fragment adds the boolean `deadlineOverdue` to the case
- WHEN the case's search declarations are read
- THEN `deadlineOverdue` declares `inputControl: boolean` and no `matchType`
