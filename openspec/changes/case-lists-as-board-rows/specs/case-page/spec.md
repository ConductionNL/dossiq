## ADDED Requirements

### Requirement: The case's next actions are ruled rows

The Taken tab's "Wat hierna komt" SHALL list the planned actions as rows under a rule, each with a calendar tile, the
action, its owner and its planned date at the end, and SHALL say how many are planned in its title row.

#### Scenario: two planned actions
- **GIVEN** a case with two planned actions
- **THEN** the card reads "2 gepland" and lists them by planned date, first the soonest
