# case-types Delta

## ADDED Requirements

### Requirement: The Overview draws a case type's statuses as coloured tiles

The Statuses card on a case type's Overview SHALL list the statuses in their order as tiles, each with the status's
colour, its name, and "Start", "Term suspended" or "End" when the status is the intake, waits on the applicant or is
final, followed by a line saying where colour and order apply.

#### Scenario: A permit case type

- **GIVEN** a case type whose first status has role intake and whose last two are final
- **WHEN** an administrator opens its Overview
- **THEN** the first tile ends in "Start", the last two in "End", and each tile shows its status colour
