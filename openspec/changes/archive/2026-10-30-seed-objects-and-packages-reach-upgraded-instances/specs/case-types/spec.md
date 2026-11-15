# Case types

## ADDED Requirements

### Requirement: A case type save is judged by the flow it names @e2e exclude backend save listener, no UI change: covered by PHPUnit

A check made while a case type is saved SHALL read the moves of the flow the
incoming case type names in `workflowDefinition`. Only when that name is empty
or does not resolve to a stored flow SHALL it fall back to the case type's
active flow.

#### Scenario: An upgraded instance holds an older active flow

- **GIVEN** an instance whose active flow for the Woo case type has no move from Ontvangst to Afgehandeld
- **AND** the shipped case type names flow `cb967581-…`, which has that move
- **WHEN** the shipped case type is saved
- **THEN** the portal withdrawal check passes and the case type is written

#### Scenario: The named flow is not stored

- **WHEN** the saved case type names a flow the instance does not hold
- **THEN** the check judges the active flow, as before

### Requirement: Packages installed on upgrade are followed by the register import @e2e exclude repair step run by occ upgrade, no UI: covered by PHPUnit

The shipped package step SHALL run on install and on every upgrade, and a
package at the version already installed SHALL write nothing. When the step
installed a package, it SHALL import the register configuration again, unforced,
and name every shipped object that import still could not write.

#### Scenario: The Woo flow arrives with the upgrade

- **GIVEN** an upgraded instance holding the Woo case type at version 1, without a handling team and without flow `cb967581-…`
- **WHEN** the upgrade runs the package step
- **THEN** the `woo-verzoek` package writes the flow
- **AND** the register is imported again, so the case type is updated to 1.4.7 with `handling.defaultGroup: behandelaars` and the flow

#### Scenario: Nothing new to install

- **WHEN** every automatic package is already current
- **THEN** the register is not imported again
