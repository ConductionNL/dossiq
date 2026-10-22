# example-set-supplier-contracts

## ADDED Requirements

### Requirement: The Zuiddrecht set has supplier contracts in every status

The Zuiddrecht example set SHALL hold six suppliers and six supplier contracts
as DqContracten draws them, covering the statuses active, renewal-decision,
termination-decision and ending, each contract pointing at a supplier of the
set. A supplier's KvK number SHALL be in the example range `000020xx`.

#### Scenario: The contracts list
- **GIVEN** the set is installed
- **WHEN** a handler opens Contracts
- **THEN** the six contracts SHALL be listed with their supplier, value, end date and status

### Requirement: A contract status is named by its label

`supplierContract.status` SHALL carry a `oneOf` entry per value whose title is
the value's `x-enum-labels` text, so a filter chip and a pill name the status
instead of its value.

#### Scenario: The status filter
- **GIVEN** the Contracts list filtered on status `ending`
- **WHEN** the Active line renders in Dutch
- **THEN** the chip SHALL name the status "Verlopen, in afbouw", not `ending`
