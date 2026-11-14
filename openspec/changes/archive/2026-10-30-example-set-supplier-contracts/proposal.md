# The Zuiddrecht set's supplier contracts

## Why

The board DqContracten draws the procurement contracts list with six contracts
from six suppliers, each with a status: active, a renewal or a termination
waiting on a decision in decidiq, or ended and winding down. The Zuiddrecht set
holds no supplier contracts, and the five on a development instance carry no
status at all, so the status column, its filter and the "Status: actief en
verlopend" chip have nothing to show.

The status values also had no `oneOf` labels. The Active line names a filter
value by its schema `oneOf` title, so a chip read the raw value
("Status: renewal-decision").

## What changes

- 18 suppliers and 34 supplier contracts in dossiq's part of the Zuiddrecht
  set: the six contracts and suppliers the board names, as it draws them
  (number, subject, supplier, value, end date and status), and 28 generated
  contracts from 12 fictional suppliers. The KvK numbers are in an example
  range (`000020xx`), never a real company's.
- The four contracts dossiq ships in `dossiq_register.json` take a status (three
  active, one ended), and the register version moves to 0.20.65 so they take it
  on an existing instance. With them the list counts what the board draws: 38
  contracts from 21 suppliers, 29 active, 2 waiting on a decision, 7 ended and
  5 with a renewal warning.
- `supplierContract.status` gets `oneOf` labels with the titles its
  `x-enum-labels` already carry, and the schema version moves to 1.3.0 so the
  change re-imports on an existing instance.
