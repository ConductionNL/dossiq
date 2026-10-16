# Case types

## ADDED Requirements

### Requirement: An open case without a team takes its case type's handling team @e2e exclude repair step run by occ upgrade, no UI: covered by PHPUnit

On install and on every upgrade, every open case whose `assignedGroup` is empty
SHALL be given its case type's current handling group
(`handling.defaultGroup`). A case that has a team, a closed case, and a case of
a case type without a handling group SHALL be left as they are. Only
`assignedGroup` SHALL be written, and a second run SHALL write nothing.

#### Scenario: A case filed while its case type had no handling block

- **GIVEN** an open case of the Woo case type with no `assignedGroup`
- **AND** the case type now has `handling.defaultGroup: behandelaars`
- **WHEN** the upgrade runs the repair step
- **THEN** the case's `assignedGroup` is `behandelaars` and its other fields are unchanged

#### Scenario: A case that already has a team

- **WHEN** an open case's `assignedGroup` names another team
- **THEN** the repair step leaves it

#### Scenario: A closed case

- **WHEN** a case without a team is in a final status
- **THEN** the repair step leaves it
