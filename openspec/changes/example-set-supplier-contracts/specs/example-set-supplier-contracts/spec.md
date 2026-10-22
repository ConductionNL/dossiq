# example-set-supplier-contracts

## ADDED Requirements

### Requirement: The Zuiddrecht set has supplier contracts in every status

The Zuiddrecht example set SHALL hold the six supplier contracts and suppliers
DqContracten names, as it draws them, among 34 contracts from 18 suppliers,
each contract pointing at a supplier of the set. With the four contracts dossiq
ships, each of which SHALL carry a status, the contracts SHALL count as the
board does: 38 in all, 29 active, one renewal and one termination waiting on a
decision, 7 ended, and 5 with a renewal warning. A supplier's KvK number in the
set SHALL be in the example range `000020xx`.

#### Scenario: The contracts list
- **GIVEN** the set is installed
- **WHEN** a handler opens Contracts
- **THEN** 38 contracts SHALL be listed, the six the board names among them with their supplier, value, end date and status
- **AND** the lenses SHALL count 29 active, 5 expiring within six months and 2 needing a decision

### Requirement: A contract status is named by its label

`supplierContract.status` SHALL carry a `oneOf` entry per value whose title is
the value's `x-enum-labels` text, so a filter chip and a pill name the status
instead of its value.

#### Scenario: The status filter
- **GIVEN** the Contracts list filtered on status `ending`
- **WHEN** the Active line renders in Dutch
- **THEN** the chip SHALL name the status "Verlopen, in afbouw", not `ending`
