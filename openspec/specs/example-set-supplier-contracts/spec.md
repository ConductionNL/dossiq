# example-set-supplier-contracts Specification

## Purpose
What dossiq does for example set supplier contracts. The requirements were first written by the change `example-set-supplier-contracts` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-supplier-contracts/`) and cover: The Zuiddrecht set has supplier contracts in every status; A contract status is named by its label.

## Requirements

### Requirement: The Zuiddrecht set has supplier contracts in every status

The Zuiddrecht example set SHALL hold the six supplier contracts and suppliers
DqContracten names, as it draws them, among 34 contracts from 18 suppliers,
each contract pointing at a supplier of the set. With the four contracts dossiq
ships, each of which SHALL carry a status, the contracts SHALL count as the
board does: 38 contracts at 25 suppliers in the subtitle, and the lenses Alles
32, Actief 26, Verloopt binnen 6 maanden 2 and Besluit nodig 2 (one renewal and
one termination waiting on a decision). A supplier's KvK number in the
set SHALL be in the example range `000020xx`.

#### Scenario: The contracts list
- **GIVEN** the set is installed
- **WHEN** a handler opens Contracts
- **THEN** the subtitle SHALL read "38 contracten bij 25 leveranciers", the six the board names among the contracts with their supplier, value, end date and status
- **AND** the lenses SHALL count Alles 32, Actief 26, Verloopt binnen 6 maanden 2 and Besluit nodig 2

### Requirement: A contract status is named by its label

`supplierContract.status` SHALL carry a `oneOf` entry per value whose title is
the value's `x-enum-labels` text, so a filter chip and a pill name the status
instead of its value.

#### Scenario: The status filter
- **GIVEN** the Contracts list filtered on status `ending`
- **WHEN** the Active line renders in Dutch
- **THEN** the chip SHALL name the status "Verlopen, in afbouw", not `ending`
