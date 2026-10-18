## ADDED Requirements

### Requirement: A package can ship example rows

A shipped package MAY declare an `examples` part. Its `examples/*.json` files
SHALL follow the seed file rules, and the installer SHALL write their rows
with the package, after the seed rows, recorded in the shipped-set ledger
like every other row.

#### Scenario: Example rows install with the package
- GIVEN an instance that loaded example data
- AND a package that declares `examples` with one case row
- WHEN the package is installed
- THEN the case is written and recorded under the package

#### Scenario: Dates are relative to the install day
- GIVEN an example row whose `startDate` is `{{date:-3}}`
- WHEN the package is installed
- THEN the case's `startDate` is the date three days before the install day

### Requirement: Example rows install only where example data is wanted

The installer SHALL write a package's example rows only when the setup
wizard's example data decision (`demo_data_decided`) is `installed`. On any
other instance, never asked or "None" chosen, it SHALL install the package
without them and log one line.

#### Scenario: A production install gets no example case
- GIVEN an instance whose operator chose "None" for example data
- WHEN a package with an `examples` part is installed
- THEN the case type is written and the example case is not

#### Scenario: An instance with example data gets the example case
- GIVEN an instance whose operator loaded example data
- WHEN a package with an `examples` part is installed
- THEN the example case is written with the package

### Requirement: The Zuiddrecht set does not depend on an on-request package

The Zuiddrecht example-set fragment SHALL NOT look up a case type or a role
type that only an on-request package ships. Example data of such a case type
SHALL ship with its package.

#### Scenario: The set installs without the on-request packages
- GIVEN an instance without bezwaar-beroep, jeugdwet and aanbesteding
- WHEN the Zuiddrecht set is checked
- THEN no lookup of dossiq's fragment refers to a key only those packages ship
